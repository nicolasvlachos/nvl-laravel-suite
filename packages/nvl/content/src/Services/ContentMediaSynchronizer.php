<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Models\ContentBlock;
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

        $current = MediaAssociation::query()
            ->where('associable_type', $block->getMorphClass())
            ->where('associable_id', $block->getKey())
            ->where('collection', 'like', 'content:%')
            ->get();

        if ($desired !== [] || $current->isNotEmpty()) {
            $this->assertSharedConnection($block);
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
                $block,
            )) {
                throw new InvalidArgumentException(
                    "Media [{$media->id}] cannot be associated with content block [{$block->id}].",
                );
            }

            $this->attach->execute(
                media: $media,
                model: $block,
                collection: $collection,
                locale: $reference['locale'],
                order: $reference['order'],
                metadata: [
                    'field_path' => $reference['path'],
                    'locale' => $reference['locale'],
                    'content_managed' => true,
                ],
                dispatchVariations: false,
            );
        }

        foreach ($current as $association) {
            $key = $association->media_id.'|'.$association->collection;

            if (! isset($desiredKeys[$key])) {
                $this->detach->execute(
                    $association->media_id,
                    $block,
                    $association->collection,
                );
            }
        }
    }

    public function detachAll(ContentBlock $block): void
    {
        $associations = MediaAssociation::query()
            ->where('associable_type', $block->getMorphClass())
            ->where('associable_id', $block->getKey())
            ->where('collection', 'like', 'content:%')
            ->get();

        if ($associations->isNotEmpty()) {
            $this->assertSharedConnection($block);
        }

        foreach ($associations as $association) {
            $this->detach->execute(
                $association->media_id,
                $block,
                $association->collection,
            );
        }
    }

    private function collection(string $path, ?string $locale): string
    {
        return 'content:'.substr(hash('sha256', ($locale ?? '*').'|'.$path), 0, 24);
    }

    private function assertSharedConnection(ContentBlock $block): void
    {
        $contentConnection = $this->database
            ->connection($block->getConnectionName())
            ->getName();
        $mediaConnection = $this->database
            ->connection((new MediaAssociation)->getConnectionName())
            ->getName();

        if ($contentConnection !== $mediaConnection) {
            throw new InvalidArgumentException(
                'Content and Media must use the same named database connection so block and media-association writes remain atomic.',
            );
        }
    }
}
