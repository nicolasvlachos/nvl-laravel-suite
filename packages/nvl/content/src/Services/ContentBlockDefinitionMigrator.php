<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use InvalidArgumentException;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\ContentDefinitionMigrationContextData;
use Nvl\Content\Enums\ContentPlacementEvent;
use Nvl\Content\Enums\ContentRevisionEvent;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Events\ContentBlockChanged;
use Nvl\Content\Events\ContentPlacementChanged;
use Nvl\Content\Exceptions\ContentDefinitionMigrationException;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Models\ContentPlacement;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Validation\ContentValueValidator;
use Nvl\Translatable\Services\TranslationWriter;
use Throwable;

/**
 * Applies, validates, and persists one complete definition migration chain.
 *
 * The caller owns the surrounding transaction and row lock.
 */
final readonly class ContentBlockDefinitionMigrator
{
    public function __construct(
        private ContentDefinitionMigrationRegistry $migrations,
        private ContentDefinitionRegistry $definitions,
        private ContentValueValidator $validator,
        private ContentPayloadGuard $guard,
        private ContentMediaSynchronizer $media,
        private ContentRevisionRecorder $revisions,
        private CanonicalJson $json,
        private TranslationWriter $translationWriter,
        private ContentOwnerRegistry $owners,
        private ContentPlacementValidator $placements,
        private ContentScopeRegistry $scopes,
    ) {}

    public function migrate(
        ContentBlock $block,
        int $targetVersion,
        ContentActorData $actor,
    ): ContentBlock {
        $block->loadMissing(['definition', 'translations']);
        $definition = $this->definitions->get($block->definition->key);

        if ($targetVersion !== $definition->version) {
            throw new InvalidArgumentException(
                "Content definition migration target [{$targetVersion}] is no longer current ".
                "for [{$definition->key}:{$definition->version}].",
            );
        }

        if ($block->definition_version >= $targetVersion) {
            throw new InvalidArgumentException(
                "Content block [{$block->id}] is not older than definition ".
                "[{$definition->key}:{$targetVersion}].",
            );
        }

        $definitionHash = $this->json->hash($definition->toArray());

        if (! hash_equals($block->definition->source_hash, $definitionHash)) {
            throw new InvalidArgumentException(
                "Content definition [{$definition->key}] is stale; synchronize it before migration.",
            );
        }

        $this->scopes->assert($block->scope, $block->scope_key, $definition);
        $values = ContentArrays::stringMap(
            is_array($block->values) ? $block->values : [],
            "content block {$block->id} values",
        );
        $translations = $this->translations($block);
        $metadata = ContentArrays::stringMap(
            is_array($block->metadata) ? $block->metadata : [],
            "content block {$block->id} metadata",
        );

        foreach ($this->migrations->path(
            $definition->key,
            $block->definition_version,
            $targetVersion,
        ) as $migration) {
            try {
                $migrated = $migration->migrate(new ContentDefinitionMigrationContextData(
                    blockId: $block->id,
                    blockKey: $block->key,
                    scope: $block->scope,
                    scopeKey: $block->scope_key,
                    status: $block->status,
                    visibility: $block->visibility,
                    fromVersion: $migration->fromVersion(),
                    toVersion: $migration->toVersion(),
                    values: $values,
                    translations: $translations,
                    metadata: $metadata,
                ));
            } catch (Throwable $exception) {
                throw ContentDefinitionMigrationException::forStep(
                    blockId: $block->id,
                    definition: $definition->key,
                    migration: $migration,
                    previous: $exception,
                );
            }

            $values = ContentArrays::stringMap(
                $migrated->values,
                "content block {$block->id} migrated values",
            );
            $translations = ContentArrays::translations(
                $migrated->translations,
                "content block {$block->id} migrated translations",
            );
            $metadata = ContentArrays::stringMap(
                $migrated->metadata,
                "content block {$block->id} migrated metadata",
            );
        }

        $schema = $definition->schema->toSchema();
        $validated = $this->validator->validate(
            schema: $schema,
            values: $values,
            translations: $translations,
            actor: $actor,
            visibility: $block->visibility,
            publishing: $block->status === ContentStatus::Published,
        );
        $this->guard->metadata($metadata);
        $block->forceFill([
            'values' => $validated->values,
            'metadata' => $metadata === [] ? null : $metadata,
            'definition_version' => $definition->version,
            'definition_hash' => $definitionHash,
            'definition_schema' => $schema,
            'definition_view' => $definition->view,
            'revision' => $block->revision + 1,
            'updated_by_type' => $actor->type,
            'updated_by_id' => $actor->id,
        ])->save();
        $this->translationWriter->replace(
            $block,
            $this->translationPayloads($validated->translations),
        );
        $block->load('translations');
        $this->validatePlacements($block, $actor);

        if (! $block->trashed()) {
            $this->media->synchronize(
                $block,
                $schema,
                $validated->values,
                $validated->translations,
                $actor,
            );
        }

        $this->revisions->record($block, ContentRevisionEvent::Migrated, $actor);
        ContentBlockChanged::dispatch(
            $block->id,
            ContentRevisionEvent::Migrated,
            $block->revision,
            $actor,
        );

        return $block->refresh()->load(['definition', 'translations']);
    }

    private function validatePlacements(ContentBlock $block, ContentActorData $actor): void
    {
        $placements = ContentPlacement::query()
            ->where('content_block_id', $block->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($placements as $placement) {
            $owner = $this->owners->resolve($placement->owner_type, $placement->owner_id);
            $this->owners->assertGroup($owner, $placement->group);
            $overrides = ContentArrays::stringMap(
                is_array($placement->overrides) ? $placement->overrides : [],
                "content placement {$placement->id} overrides",
            );
            $normalized = $this->placements->validateDefinition(
                $block,
                $owner,
                $placement->group,
                $placement->region,
                $overrides,
                $actor,
            );

            if ($normalized === $overrides) {
                continue;
            }

            $placement->forceFill([
                'overrides' => $normalized === [] ? null : $normalized,
                'revision' => $placement->revision + 1,
            ])->save();
            ContentPlacementChanged::dispatch(
                $placement->id,
                ContentPlacementEvent::Updated,
                $placement->revision,
                $actor,
                $placement->owner_type,
                $placement->owner_id,
                $placement->group,
                $block->id,
            );
        }
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

        ksort($translations);

        return $translations;
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
