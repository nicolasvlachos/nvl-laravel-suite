<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use InvalidArgumentException;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Content\Services\ContentLocalePolicy;
use Nvl\Content\Services\ContentPatch;
use Nvl\Content\Services\ContentScopeRegistry;
use Nvl\Content\Validation\ContentValueValidator;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Models\Media;
use Nvl\Templates\Data\MediaTemplateAssetData;
use Nvl\Templates\Enums\TemplateStatus;

/**
 * Validates and normalizes the bounded source-to-suite adoption manifest.
 *
 * @phpstan-type TemplateItem array{legacy_key: string, key: string, status: TemplateStatus, metadata: array<string, mixed>, translations: array<string, array<string, mixed>>}
 * @phpstan-type ContentItem array{legacy_identity: string, identity: string, definition: string, key: string, scope: string, scopeKey: string, visibility: ContentVisibility, values: array<string, mixed>, translations: array<string, array<string, mixed>>, metadata: array<string, mixed>, publish: bool}
 * @phpstan-type AssetItem array{legacy_alias: string, key: string, mediaId: string, scope: string, type: string, variation: string, delivery: string, expectedRevision: int|null, asset: MediaTemplateAssetData}
 * @phpstan-type NormalizedManifest array{staging_connection: string, staging_tables: list<string>, legacy_asset_count: int, templates: list<TemplateItem>, content: list<ContentItem>, assets: list<AssetItem>}
 */
final readonly class TemplateAdoptionManifest
{
    public function __construct(
        private TemplateDefinitionRegistry $templateDefinitions,
        private ContentDefinitionRegistry $contentDefinitions,
        private ContentScopeRegistry $contentScopes,
        private ContentLocalePolicy $locales,
        private ContentPatch $contentPatch,
        private ContentValueValidator $contentValues,
        private TemplateContentGuard $templateGuard,
        private MediaTemplateAssetRegistry $assets,
    ) {}

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return NormalizedManifest
     */
    public function normalize(array $manifest): array
    {
        $unknown = array_diff(array_keys($manifest), [
            'version',
            'staging_connection',
            'staging_tables',
            'legacy_asset_count',
            'templates',
            'content',
            'assets',
        ]);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Adoption manifest contains unknown option ['.(string) reset($unknown).'].',
            );
        }

        if (($manifest['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Adoption manifest version must be 1.');
        }

        $connection = $manifest['staging_connection'] ?? config('database.default');

        if (! is_string($connection) || trim($connection) === '') {
            throw new InvalidArgumentException('Adoption staging_connection must be a connection name.');
        }

        $stagingTables = $this->stringList($manifest['staging_tables'] ?? [], 'staging_tables');
        $templates = $this->objectList($manifest['templates'] ?? [], 'templates');
        $content = $this->objectList($manifest['content'] ?? [], 'content');
        $assets = $this->objectList($manifest['assets'] ?? [], 'assets');
        $legacyAssetCount = $manifest['legacy_asset_count'] ?? null;

        if (! is_int($legacyAssetCount) || $legacyAssetCount < 0) {
            throw new InvalidArgumentException('Adoption legacy_asset_count must be a non-negative integer.');
        }

        if ($legacyAssetCount !== count($assets)) {
            throw new InvalidArgumentException(
                'Adoption maps '.count($assets)." assets but declares {$legacyAssetCount} legacy assets.",
            );
        }

        $maximumRecords = config('templates.adoption.maximum_records', 10_000);

        if (! is_int($maximumRecords) || $maximumRecords < 1) {
            throw new InvalidArgumentException(
                'templates.adoption.maximum_records must be a positive integer.',
            );
        }

        if (count($templates) + count($content) + count($assets) > $maximumRecords) {
            throw new InvalidArgumentException(
                "Adoption manifest exceeds the configured {$maximumRecords} record limit.",
            );
        }

        $templates = array_map($this->normalizeTemplate(...), $templates);
        $content = array_map($this->normalizeContent(...), $content);
        $assets = array_map($this->normalizeAsset(...), $assets);
        $this->assertUnique($templates, 'legacy_key', 'template source key');
        $this->assertUnique($templates, 'key', 'template key');
        $this->assertUnique($content, 'legacy_identity', 'content source identity');
        $this->assertUnique($content, 'identity', 'content target identity');
        $this->assertUnique($assets, 'legacy_alias', 'asset source alias');
        $this->assertUnique($assets, 'key', 'asset alias');

        return [
            'staging_connection' => $connection,
            'staging_tables' => $stagingTables,
            'legacy_asset_count' => $legacyAssetCount,
            'templates' => $templates,
            'content' => $content,
            'assets' => $assets,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return TemplateItem
     */
    private function normalizeTemplate(array $item): array
    {
        $this->exactKeys($item, ['legacy_key', 'key', 'status', 'metadata', 'translations'], 'template');
        $sourceKey = $this->requiredString($item, 'legacy_key', 'template');
        $key = $this->requiredString($item, 'key', 'template');
        $status = TemplateStatus::tryFrom($this->optionalString($item, 'status', 'active'));

        if (! $status instanceof TemplateStatus) {
            throw new InvalidArgumentException("Adoption template [{$sourceKey}] has an invalid status.");
        }

        $metadata = $this->stringMap($item['metadata'] ?? [], "template {$sourceKey} metadata");
        $translations = $this->translations($item['translations'] ?? [], "template {$sourceKey}");
        $this->templateGuard->metadata($metadata);

        foreach ($translations as $locale => $translation) {
            $this->locales->assertSupported($locale);
            $title = $translation['title'] ?? null;
            $description = $translation['description'] ?? null;

            if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
                throw new InvalidArgumentException(
                    "Adoption template [{$sourceKey}] translation [{$locale}] requires a title of at most 255 characters.",
                );
            }

            if ($description !== null && ! is_string($description)) {
                throw new InvalidArgumentException(
                    "Adoption template [{$sourceKey}] translation [{$locale}] description must be a string or null.",
                );
            }
        }

        $this->templateDefinitions->get($key);

        return [
            'legacy_key' => $sourceKey,
            'key' => $key,
            'status' => $status,
            'metadata' => $metadata,
            'translations' => $translations,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return ContentItem
     */
    private function normalizeContent(array $item): array
    {
        $this->exactKeys($item, [
            'legacy_key',
            'legacy_scope',
            'legacy_scope_key',
            'definition',
            'key',
            'scope',
            'scope_key',
            'visibility',
            'values',
            'translations',
            'metadata',
            'publish',
        ], 'content');
        $sourceKey = $this->requiredString($item, 'legacy_key', 'content');
        $sourceScope = $this->requiredString($item, 'legacy_scope', 'content');
        $sourceScopeKey = $this->requiredString($item, 'legacy_scope_key', 'content');
        $definition = $this->requiredString($item, 'definition', 'content');
        $key = $this->requiredString($item, 'key', 'content');
        $scope = $this->requiredString($item, 'scope', 'content');
        $scopeKey = $this->requiredString($item, 'scope_key', 'content');
        $visibility = ContentVisibility::tryFrom(
            $this->optionalString($item, 'visibility', 'public'),
        );

        if (! $visibility instanceof ContentVisibility) {
            throw new InvalidArgumentException(
                "Adoption content [{$sourceKey}] has an invalid visibility.",
            );
        }

        $publish = $item['publish'] ?? true;

        if (! is_bool($publish)) {
            throw new InvalidArgumentException("Adoption content [{$sourceKey}] publish must be boolean.");
        }

        $values = $this->stringMap($item['values'] ?? [], "content {$sourceKey} values");
        $translations = $this->translations($item['translations'] ?? [], "content {$sourceKey}");
        $metadata = $this->stringMap($item['metadata'] ?? [], "content {$sourceKey} metadata");
        $contentDefinition = $this->contentDefinitions->get($definition);
        $this->contentScopes->assert($scope, $scopeKey, $contentDefinition);
        $validated = $this->contentValues->validate(
            $contentDefinition->schema->toSchema(),
            $this->contentPatch->merge($contentDefinition->defaults, $values),
            $translations,
            ContentActorData::system(),
            $visibility,
            publishing: $publish,
        );

        return [
            'legacy_identity' => "{$sourceScope}:{$sourceScopeKey}:{$sourceKey}",
            'identity' => "{$scope}:{$scopeKey}:{$key}",
            'definition' => $definition,
            'key' => $key,
            'scope' => $scope,
            'scopeKey' => $scopeKey,
            'visibility' => $visibility,
            'values' => $validated->values,
            'translations' => $validated->translations,
            'metadata' => $metadata,
            'publish' => $publish,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return AssetItem
     */
    private function normalizeAsset(array $item): array
    {
        $this->exactKeys($item, [
            'legacy_alias',
            'key',
            'media_id',
            'scope',
            'type',
            'variation',
            'delivery',
            'expected_revision',
        ], 'asset');
        $sourceAlias = $this->requiredString($item, 'legacy_alias', 'asset');
        $key = $this->requiredString($item, 'key', 'asset');
        $mediaId = $this->requiredString($item, 'media_id', 'asset');
        $scope = $this->optionalString($item, 'scope', 'adoption');
        $type = $this->optionalString($item, 'type', 'image');
        $variation = $this->optionalString($item, 'variation', '');
        $delivery = $this->optionalString($item, 'delivery', 'path');
        $expectedRevision = $item['expected_revision'] ?? null;

        if ($expectedRevision !== null && (! is_int($expectedRevision) || $expectedRevision < 1)) {
            throw new InvalidArgumentException(
                "Adoption asset [{$sourceAlias}] expected_revision must be positive.",
            );
        }

        $media = Media::query()->with('imageVariations')->available()->find($mediaId);

        if (! $media instanceof Media) {
            throw new InvalidArgumentException(
                "Adoption asset [{$sourceAlias}] maps to missing or unavailable Media [{$mediaId}].",
            );
        }

        if ($expectedRevision !== null && $media->revision !== $expectedRevision) {
            throw new InvalidArgumentException(
                "Adoption asset [{$sourceAlias}] expects Media revision {$expectedRevision}, current revision is {$media->revision}.",
            );
        }

        $asset = new MediaTemplateAssetData(
            key: $key,
            mediaId: $mediaId,
            scope: $scope,
            type: $type,
            variation: $variation,
            delivery: $delivery,
            expectedRevision: $expectedRevision,
        );
        $this->assets->validate($asset);

        if ($variation !== '') {
            $variationModel = $media->getVariation($variation);

            if ($variationModel === null
                || $variationModel->status !== MediaLifecycleStatus::Available->value
                || $variationModel->source_revision !== $media->revision) {
                throw new InvalidArgumentException(
                    "Adoption asset [{$sourceAlias}] variation [{$variation}] is unavailable for Media revision {$media->revision}.",
                );
            }
        }

        return [
            'legacy_alias' => $sourceAlias,
            'key' => $key,
            'mediaId' => $mediaId,
            'scope' => $scope,
            'type' => $type,
            'variation' => $variation,
            'delivery' => $delivery,
            'expectedRevision' => $expectedRevision,
            'asset' => $asset,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function assertUnique(array $items, string $field, string $label): void
    {
        $seen = [];

        foreach ($items as $item) {
            $value = $item[$field] ?? null;

            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("Adoption {$label} values must be strings.");
            }

            if (isset($seen[$value])) {
                throw new InvalidArgumentException("Adoption {$label} values must be unique.");
            }

            $seen[$value] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $allowed
     */
    private function exactKeys(array $item, array $allowed, string $label): void
    {
        $unknown = array_diff(array_keys($item), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Adoption {$label} contains unknown option [".(string) reset($unknown).'].',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Adoption {$label} must be a list.");
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $item) !== 1) {
                throw new InvalidArgumentException(
                    "Adoption {$label} must contain only safe table names.",
                );
            }

            $strings[] = $item;
        }

        return array_values(array_unique($strings));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function objectList(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Adoption {$label} must be a list.");
        }

        $objects = [];

        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException("Every adoption {$label} entry must be an object.");
            }

            /** @var array<string, mixed> $item */
            $objects[] = $item;
        }

        return $objects;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function requiredString(array $item, string $key, string $label): string
    {
        $value = $item[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Adoption {$label}.{$key} must be a non-empty string.");
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function optionalString(array $item, string $key, string $default): string
    {
        if (! array_key_exists($key, $item)) {
            return $default;
        }

        $value = $item[$key];

        if (! is_string($value) || ($default !== '' && trim($value) === '')) {
            throw new InvalidArgumentException("Adoption {$key} must be a string.");
        }

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function stringMap(mixed $value, string $label): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException("Adoption {$label} must be an object.");
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("Adoption {$label} must use string keys.");
            }

            $map[$key] = $item;
        }

        return $map;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function translations(mixed $value, string $label): array
    {
        $translations = $this->stringMap($value, "{$label} translations");
        $normalized = [];

        foreach ($translations as $locale => $fields) {
            $normalized[$locale] = $this->stringMap(
                $fields,
                "{$label} translation {$locale}",
            );
        }

        ksort($normalized);

        return $normalized;
    }
}
