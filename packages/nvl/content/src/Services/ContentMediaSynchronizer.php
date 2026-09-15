<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Schema\ContentSchema;
use Nvl\Media\Actions\AttachMediaAction;
use Nvl\Media\Actions\DetachMediaAction;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;

/**
 * Keeps Media associations aligned with normalized field IDs without owning binaries.
 */
final readonly class ContentMediaSynchronizer
{
    public function __construct(
        private ContentMediaReferences $references,
        private AttachMediaAction $attach,
        private DetachMediaAction $detach,
        private MediaAuthorization $authorization,
        private DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function synchronize(
        ContentBlock $block,
        ContentSchema $schema,
        array $values,
        array $translations,
        ContentActorData $actor,
    ): void {
        $desired = $this->references->extract($schema, $values, null);

        foreach ($translations as $locale => $localizedValues) {
            $desired = [
                ...$desired,
                ...$this->references->extract($schema, $localizedValues, $locale),
            ];
        }

        $this->synchronizeReferences($block, $desired, $actor, $block);
    }

    /**
     * Synchronize only Media explicitly referenced by one placement's overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function synchronizePlacement(
        ContentPlacement $placement,
        ContentSchema $schema,
        array $overrides,
        ContentActorData $actor,
        Model&ContentOwner $owner,
    ): void {
        $this->synchronizeReferences(
            $placement,
            $this->references->extract($schema, $overrides, null),
            $actor,
            $owner,
        );
    }

    /**
     * Detach every Content-managed association while preserving Media records.
     */
    public function detachAll(ContentBlock|ContentPlacement $model): void
    {
        $associations = MediaAssociation::query()
            ->where('associable_type', $model->getMorphClass())
            ->where('associable_id', $model->getKey())
            ->where('collection', 'like', 'content:%')
            ->orderBy('media_id')
            ->get();

        if ($associations->isNotEmpty()) {
            $this->assertSharedConnection($model);
        }

        foreach ($associations as $association) {
            $this->detach->execute(
                $association->media_id,
                $model,
                $association->collection,
            );
        }
    }

    /**
     * Reconcile one stable association target using Media's mutation Actions.
     *
     * @param  list<array{id: string, path: string, locale: string|null, order: int}>  $desired
     */
    private function synchronizeReferences(
        ContentBlock|ContentPlacement $model,
        array $desired,
        ContentActorData $actor,
        Model $authorizationOwner,
    ): void {
        usort($desired, static fn (array $left, array $right): int => [
            $left['id'], $left['path'], $left['locale'],
        ] <=> [$right['id'], $right['path'], $right['locale']]);

        $current = MediaAssociation::query()
            ->where('associable_type', $model->getMorphClass())
            ->where('associable_id', $model->getKey())
            ->where('collection', 'like', 'content:%')
            ->orderBy('media_id')
            ->get();

        if ($desired !== [] || $current->isNotEmpty()) {
            $this->assertSharedConnection($model);
        }

        $desiredKeys = [];

        foreach ($desired as $reference) {
            $collection = $this->collection($reference['path'], $reference['locale']);
            $key = $reference['id'].'|'.$collection;
            $desiredKeys[$key] = true;
            $media = Media::query()->findOrFail($reference['id']);
            $ability = $media->visibility === MediaVisibility::Public
                ? MediaAbility::Reuse
                : MediaAbility::Associate;

            if (! $this->authorization->allows(
                new MediaActorData($actor->type, $actor->id, system: $actor->system),
                $ability,
                $media,
                $authorizationOwner,
            )) {
                throw new InvalidArgumentException(
                    "Media [{$media->id}] cannot be associated with content [{$model->id}].",
                );
            }

            $this->attach->execute(
                media: $media,
                model: $model,
                collection: $collection,
                locale: $reference['locale'],
                order: $reference['order'],
                metadata: [
                    'field_path' => $reference['path'],
                    'locale' => $reference['locale'],
                    'content_managed' => true,
                ],
                dispatchVariations: false,
                requirePublic: $media->visibility === MediaVisibility::Public,
            );
        }

        foreach ($current as $association) {
            $key = $association->media_id.'|'.$association->collection;

            if (! isset($desiredKeys[$key])) {
                $this->detach->execute(
                    $association->media_id,
                    $model,
                    $association->collection,
                );
            }
        }
    }

    private function collection(string $path, ?string $locale): string
    {
        return 'content:'.substr(hash('sha256', ($locale ?? '*').'|'.$path), 0, 24);
    }

    private function assertSharedConnection(ContentBlock|ContentPlacement $model): void
    {
        $contentConnection = $this->database
            ->connection($model->getConnectionName())
            ->getName();
        $mediaConnection = $this->database
            ->connection((new MediaAssociation)->getConnectionName())
            ->getName();

        if ($contentConnection !== $mediaConnection) {
            throw new InvalidArgumentException(
                'Content and Media must use the same named database connection so content and media-association writes remain atomic.',
            );
        }
    }
}
