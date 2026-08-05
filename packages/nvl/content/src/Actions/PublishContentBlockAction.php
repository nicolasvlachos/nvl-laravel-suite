<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentRevisionEvent;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Events\ContentBlockChanged;
use Nvl\Content\Exceptions\StaleContentException;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Services\CanonicalJson;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentDefinitionVersionGuard;
use Nvl\Content\Services\ContentMediaSynchronizer;
use Nvl\Content\Services\ContentRevisionRecorder;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Validation\ContentValueValidator;

/**
 * Validates every required locale and publishes one exact revision.
 */
final readonly class PublishContentBlockAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentDefinitionRegistry $definitions,
        private ContentDefinitionVersionGuard $definitionVersions,
        private ContentValueValidator $validator,
        private ContentMediaSynchronizer $media,
        private ContentRevisionRecorder $revisions,
        private CanonicalJson $json,
    ) {}

    public function execute(
        ContentBlock|string $block,
        int $expectedRevision,
        ContentActorData $actor,
    ): ContentBlock {
        $blockId = $block instanceof ContentBlock ? $block->id : $block;

        return DB::connection((new ContentBlock)->getConnectionName())
            ->transaction(function () use ($actor, $blockId, $expectedRevision): ContentBlock {
                $model = ContentBlock::query()
                    ->with(['definition', 'translations'])
                    ->lockForUpdate()
                    ->findOrFail($blockId);
                $this->authorization->authorize(ContentAbility::Publish, $actor, $model);

                if ($model->revision !== $expectedRevision) {
                    throw StaleContentException::forRevision(
                        $model->id,
                        $expectedRevision,
                        $model->revision,
                    );
                }

                $definition = $this->definitions->get($model->definition->key);
                $definitionHash = $this->json->hash($definition->toArray());

                if (! hash_equals($model->definition->source_hash, $definitionHash)) {
                    throw new InvalidArgumentException(
                        "Content definition [{$definition->key}] is stale; synchronize it before publishing.",
                    );
                }

                $this->definitionVersions->assertCurrent($model, $definition);
                $schema = $definition->schema->toSchema();
                $translations = [];

                foreach ($model->translations as $translation) {
                    $locale = $translation->getAttribute('locale');
                    $values = $translation->getAttribute('values');

                    if (is_string($locale) && is_array($values)) {
                        $translations[$locale] = ContentArrays::stringMap(
                            $values,
                            "content translation {$locale}",
                        );
                    }
                }

                $validated = $this->validator->validate(
                    $schema,
                    is_array($model->values) ? $model->values : [],
                    $translations,
                    $actor,
                    $model->visibility,
                    publishing: true,
                );

                $model->forceFill([
                    'status' => ContentStatus::Published,
                    'values' => $validated->values,
                    'definition_version' => $definition->version,
                    'definition_hash' => $definitionHash,
                    'definition_schema' => $schema,
                    'definition_view' => $definition->view,
                    'revision' => $model->revision + 1,
                    'published_by_type' => $actor->type,
                    'published_by_id' => $actor->id,
                    'published_at' => now(),
                    'updated_by_type' => $actor->type,
                    'updated_by_id' => $actor->id,
                ])->save();
                $this->media->synchronize(
                    $model,
                    $schema,
                    $validated->values,
                    $validated->translations,
                    $actor,
                );
                $this->revisions->record($model, ContentRevisionEvent::Published, $actor);
                ContentBlockChanged::dispatch(
                    $model->id,
                    ContentRevisionEvent::Published,
                    $model->revision,
                    $actor,
                );

                return $model->refresh()->load(['definition', 'translations']);
            });
    }
}
