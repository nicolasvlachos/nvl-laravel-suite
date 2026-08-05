<?php

declare(strict_types=1);

namespace Nvl\Content\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Content\Contracts\ContentAuthorization;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\UpdateContentBlockData;
use Nvl\Content\Enums\ContentAbility;
use Nvl\Content\Enums\ContentMutationMode;
use Nvl\Content\Enums\ContentRevisionEvent;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Events\ContentBlockChanged;
use Nvl\Content\Exceptions\StaleContentException;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Services\CanonicalJson;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentDefinitionVersionGuard;
use Nvl\Content\Services\ContentMediaSynchronizer;
use Nvl\Content\Services\ContentPatch;
use Nvl\Content\Services\ContentPayloadGuard;
use Nvl\Content\Services\ContentRevisionRecorder;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Validation\ContentValueValidator;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Replaces or patches editable block content with optimistic concurrency.
 */
final readonly class UpdateContentBlockAction
{
    public function __construct(
        private ContentAuthorization $authorization,
        private ContentDefinitionRegistry $definitions,
        private ContentDefinitionVersionGuard $definitionVersions,
        private ContentValueValidator $validator,
        private ContentPatch $patch,
        private ContentPayloadGuard $guard,
        private ContentMediaSynchronizer $media,
        private ContentRevisionRecorder $revisions,
        private CanonicalJson $json,
        private TranslationWriter $translationWriter,
    ) {}

    public function execute(
        ContentBlock|string $block,
        UpdateContentBlockData $data,
        ContentActorData $actor,
    ): ContentBlock {
        $blockId = $block instanceof ContentBlock ? $block->id : $block;
        $connection = (new ContentBlock)->getConnectionName();
        $inputValues = ContentArrays::stringMap($data->values, 'content block values');
        $inputTranslations = ContentArrays::translations(
            $data->translations,
            'content block translations',
        );
        $inputMetadata = ContentArrays::stringMap(
            $data->metadata,
            'content block metadata',
        );

        return DB::connection($connection)->transaction(function () use (
            $actor,
            $blockId,
            $data,
            $inputMetadata,
            $inputTranslations,
            $inputValues,
        ): ContentBlock {
            $model = ContentBlock::query()
                ->with(['definition', 'translations'])
                ->lockForUpdate()
                ->findOrFail($blockId);
            $visibility = $data->visibility ?? $model->visibility;
            $authorizationContext = [
                'definition' => $model->definition->key,
                'key' => $model->key,
                'scope' => $model->scope,
                'scope_key' => $model->scope_key,
                'status' => $model->status->value,
                'current_visibility' => $model->visibility->value,
                'target_visibility' => $visibility->value,
                'mutation_mode' => $data->mode->value,
                'published' => $model->status === ContentStatus::Published,
            ];
            $this->authorization->authorize(
                ContentAbility::Update,
                $actor,
                $model,
                context: $authorizationContext,
            );

            if ($model->status === ContentStatus::Published
                && $model->visibility === ContentVisibility::Private
                && $visibility === ContentVisibility::Public) {
                $this->authorization->authorize(
                    ContentAbility::Publish,
                    $actor,
                    $model,
                    context: [
                        ...$authorizationContext,
                        'visibility_transition' => true,
                    ],
                );
            }

            if ($model->revision !== $data->expectedRevision) {
                throw StaleContentException::forRevision(
                    $model->id,
                    $data->expectedRevision,
                    $model->revision,
                );
            }

            $definition = $this->definitions->get($model->definition->key);
            $definitionHash = $this->json->hash($definition->toArray());

            if (! hash_equals($model->definition->source_hash, $definitionHash)) {
                throw new InvalidArgumentException(
                    "Content definition [{$definition->key}] is stale; synchronize it before editing.",
                );
            }

            $this->definitionVersions->assertCurrent($model, $definition);
            $schema = $definition->schema->toSchema();
            $currentTranslations = $this->translations($model);
            $values = $data->mode === ContentMutationMode::Patch
                ? $this->patch->merge(is_array($model->values) ? $model->values : [], $inputValues)
                : $inputValues;
            $translations = $data->mode === ContentMutationMode::Patch
                ? $this->patchTranslations($currentTranslations, $inputTranslations)
                : $inputTranslations;
            $metadata = $data->mode === ContentMutationMode::Patch
                ? $this->patch->merge(is_array($model->metadata) ? $model->metadata : [], $inputMetadata)
                : $inputMetadata;
            $validated = $this->validator->validate(
                $schema,
                $values,
                $translations,
                $actor,
                $visibility,
                publishing: $model->status === ContentStatus::Published,
            );
            $this->guard->metadata($metadata);
            $model->forceFill([
                'visibility' => $visibility,
                'values' => $validated->values,
                'metadata' => $metadata === [] ? null : $metadata,
                'definition_version' => $definition->version,
                'definition_hash' => $definitionHash,
                'definition_schema' => $schema,
                'definition_view' => $definition->view,
                'revision' => $model->revision + 1,
                'updated_by_type' => $actor->type,
                'updated_by_id' => $actor->id,
            ])->save();
            $this->translationWriter->replace(
                $model,
                $this->translationPayloads($validated->translations),
            );
            $model->load('translations');
            $this->media->synchronize(
                $model,
                $schema,
                $validated->values,
                $validated->translations,
                $actor,
            );
            $this->revisions->record($model, ContentRevisionEvent::Updated, $actor);
            ContentBlockChanged::dispatch(
                $model->id,
                ContentRevisionEvent::Updated,
                $model->revision,
                $actor,
            );

            return $model->refresh()->load(['definition', 'translations']);
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function translations(ContentBlock $block): array
    {
        $translations = [];

        foreach ($block->translations as $translation) {
            $locale = $translation->getAttribute('locale');
            $values = $translation->getAttribute('values');

            if (is_string($locale) && is_array($values)) {
                $translations[$locale] = ContentArrays::stringMap(
                    $values,
                    "content translation {$locale}",
                );
            }
        }

        return $translations;
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $patches
     * @return array<string, array<string, mixed>>
     */
    private function patchTranslations(array $current, array $patches): array
    {
        foreach ($patches as $locale => $patch) {
            $current[$locale] = $this->patch->merge($current[$locale] ?? [], $patch);
        }

        return $current;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, array{values: array<string, mixed>}>
     */
    private function translationPayloads(array $translations): array
    {
        $payloads = [];

        foreach ($translations as $locale => $values) {
            $payloads[$locale] = ['values' => $values];
        }

        return $payloads;
    }
}
