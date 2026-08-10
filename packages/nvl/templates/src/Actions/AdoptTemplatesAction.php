<?php

declare(strict_types=1);

namespace Nvl\Templates\Actions;

use InvalidArgumentException;
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Actions\PublishContentBlockAction;
use Nvl\Content\Actions\UpdateContentBlockAction;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\UpdateContentBlockData;
use Nvl\Content\Enums\ContentMutationMode;
use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Models\ContentBlock;
use Nvl\Templates\Data\Mutations\CreateTemplateData;
use Nvl\Templates\Data\Mutations\UpdateTemplateData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Services\MediaTemplateAssetRegistry;
use Nvl\Templates\Services\TemplateAdoptionManifest;
use Nvl\Templates\Services\TemplateAdoptionSchema;

/**
 * Orchestrates the source-system adoption workflow through canonical Actions.
 *
 * Manifest validation and schema preparation delegate to focused technical
 * services; all Template and Content mutations remain Action-backed here.
 *
 * @phpstan-import-type TemplateItem from TemplateAdoptionManifest
 * @phpstan-import-type ContentItem from TemplateAdoptionManifest
 * @phpstan-import-type AssetItem from TemplateAdoptionManifest
 * @phpstan-import-type NormalizedManifest from TemplateAdoptionManifest
 *
 * @phpstan-type Operation array{key: string, operation: string}
 */
final readonly class AdoptTemplatesAction
{
    public function __construct(
        private CreateTemplateAction $createTemplate,
        private UpdateTemplateAction $updateTemplate,
        private CreateContentBlockAction $createBlock,
        private UpdateContentBlockAction $updateBlock,
        private PublishContentBlockAction $publishBlock,
        private TemplateAdoptionManifest $manifests,
        private TemplateAdoptionSchema $schemas,
        private MediaTemplateAssetRegistry $assets,
    ) {}

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function execute(
        array $manifest,
        bool $prepare = false,
        bool $apply = false,
    ): array {
        $normalized = $this->manifests->normalize($manifest);
        $inventory = $this->schemas->inventory(
            $normalized['staging_connection'],
            $normalized['staging_tables'],
        );
        $preparedIndexes = $prepare
            ? $this->schemas->prepare(
                $normalized['staging_connection'],
                $normalized['staging_tables'],
            )
            : [];
        $operations = [
            'templates' => [],
            'content' => [],
            'assets' => [],
        ];

        if ($apply) {
            $this->schemas->assertCanonical($inventory['canonical']);
            $operations['templates'] = $this->applyTemplates($normalized['templates']);
            $operations['content'] = $this->applyContent($normalized['content']);
            $operations['assets'] = $this->applyAssets($normalized['assets']);
        }

        $reconciliation = $this->reconcile($normalized, $operations, $apply);

        foreach ($reconciliation as $resource => $counts) {
            if ($apply && $counts['matched'] !== $counts['expected']) {
                throw new InvalidArgumentException(
                    "Adoption reconciliation failed for {$resource}: expected {$counts['expected']}, matched {$counts['matched']}.",
                );
            }
        }

        return [
            'version' => 1,
            'mode' => $apply ? 'apply' : ($prepare ? 'prepare' : 'plan'),
            'inventory' => $inventory,
            'prepared_indexes' => $preparedIndexes,
            'operations' => $operations,
            'reconciliation' => $reconciliation,
            'media_aliases' => $this->mediaAliasConfig($normalized['assets']),
            'healthy' => true,
        ];
    }

    /**
     * @param  list<TemplateItem>  $templates
     * @return list<Operation>
     */
    private function applyTemplates(array $templates): array
    {
        $operations = [];
        $actor = TemplateActorData::system();

        foreach ($templates as $item) {
            $template = Template::query()->with('translations')->where('key', $item['key'])->first();

            if (! $template instanceof Template) {
                $this->createTemplate->execute(new CreateTemplateData(
                    key: $item['key'],
                    status: $item['status'],
                    metadata: $item['metadata'],
                    translations: $item['translations'],
                ), $actor);
                $operation = 'created';
            } elseif ($this->templateMatches($template, $item)) {
                $operation = 'unchanged';
            } else {
                $this->updateTemplate->execute($template, new UpdateTemplateData(
                    status: $item['status'],
                    expectedRevision: $template->revision,
                    metadata: $item['metadata'],
                    translations: $item['translations'],
                ), $actor);
                $operation = 'updated';
            }

            $operations[] = ['key' => $item['key'], 'operation' => $operation];
        }

        return $operations;
    }

    /**
     * @param  list<ContentItem>  $content
     * @return list<Operation>
     */
    private function applyContent(array $content): array
    {
        $operations = [];
        $actor = ContentActorData::system();

        foreach ($content as $item) {
            $block = ContentBlock::query()
                ->with(['definition', 'translations'])
                ->where('scope', $item['scope'])
                ->where('scope_key', $item['scopeKey'])
                ->where('key', $item['key'])
                ->first();

            if (! $block instanceof ContentBlock) {
                $block = $this->createBlock->execute(new CreateContentBlockData(
                    definition: $item['definition'],
                    key: $item['key'],
                    scope: $item['scope'],
                    scopeKey: $item['scopeKey'],
                    visibility: $item['visibility'],
                    values: $item['values'],
                    translations: $item['translations'],
                    metadata: $item['metadata'],
                ), $actor);
                $operation = 'created';
            } elseif ($block->definition->key !== $item['definition']) {
                throw new InvalidArgumentException(
                    "Content adoption target [{$item['identity']}] already uses definition [{$block->definition->key}].",
                );
            } elseif ($this->contentMatches($block, $item)) {
                $operation = 'unchanged';
            } else {
                if (! $item['publish'] && $block->status === ContentStatus::Published) {
                    throw new InvalidArgumentException(
                        "Content adoption cannot demote published target [{$item['identity']}] to draft.",
                    );
                }

                $block = $this->updateBlock->execute($block, new UpdateContentBlockData(
                    expectedRevision: $block->revision,
                    mode: ContentMutationMode::Replace,
                    visibility: $item['visibility'],
                    values: $item['values'],
                    translations: $item['translations'],
                    metadata: $item['metadata'],
                ), $actor);
                $operation = 'updated';
            }

            if ($item['publish'] && $block->status !== ContentStatus::Published) {
                $this->publishBlock->execute($block, $block->revision, $actor);
            }

            $operations[] = ['key' => $item['identity'], 'operation' => $operation];
        }

        return $operations;
    }

    /**
     * @param  list<AssetItem>  $assets
     * @return list<Operation>
     */
    private function applyAssets(array $assets): array
    {
        $operations = [];

        foreach ($assets as $item) {
            $existing = $this->assets->get($item['key']);

            if ($existing === null) {
                $this->assets->register($item['asset']);
                $operation = 'created';
            } elseif ($existing == $item['asset']) {
                $operation = 'unchanged';
            } else {
                throw new InvalidArgumentException(
                    "Template Media alias [{$item['key']}] is already registered differently.",
                );
            }

            $operations[] = ['key' => $item['key'], 'operation' => $operation];
        }

        return $operations;
    }

    /**
     * @param  NormalizedManifest  $normalized
     * @param  array<string, list<Operation>>  $operations
     * @return array<string, array{expected: int, matched: int, created: int, updated: int, unchanged: int}>
     */
    private function reconcile(array $normalized, array $operations, bool $apply): array
    {
        $result = [];

        foreach (['templates', 'content', 'assets'] as $resource) {
            $expected = count($normalized[$resource]);
            $resourceOperations = $operations[$resource];
            $result[$resource] = [
                'expected' => $expected,
                'matched' => $apply
                    ? $this->matchedTargets($resource, $normalized)
                    : $expected,
                'created' => $this->operationCount($resourceOperations, 'created'),
                'updated' => $this->operationCount($resourceOperations, 'updated'),
                'unchanged' => $this->operationCount($resourceOperations, 'unchanged'),
            ];
        }

        return $result;
    }

    /**
     * @param  NormalizedManifest  $normalized
     */
    private function matchedTargets(string $resource, array $normalized): int
    {
        if ($resource === 'templates') {
            return Template::query()
                ->whereIn('key', array_column($normalized['templates'], 'key'))
                ->count();
        }

        if ($resource === 'assets') {
            return count(array_filter(
                $normalized['assets'],
                fn (array $item): bool => $this->assets->get($item['key']) == $item['asset'],
            ));
        }

        $matched = 0;

        foreach ($normalized['content'] as $item) {
            $matched += ContentBlock::query()
                ->where('scope', $item['scope'])
                ->where('scope_key', $item['scopeKey'])
                ->where('key', $item['key'])
                ->exists() ? 1 : 0;
        }

        return $matched;
    }

    /**
     * @param  list<Operation>  $operations
     */
    private function operationCount(array $operations, string $operation): int
    {
        return count(array_filter(
            $operations,
            static fn (array $item): bool => $item['operation'] === $operation,
        ));
    }

    /**
     * @param  list<AssetItem>  $assets
     * @return array<string, array<string, mixed>>
     */
    private function mediaAliasConfig(array $assets): array
    {
        $config = [];

        foreach ($assets as $item) {
            $config[$item['key']] = [
                'media_id' => $item['mediaId'],
                'scope' => $item['scope'],
                'type' => $item['type'],
                'variation' => $item['variation'],
                'delivery' => $item['delivery'],
                'expected_revision' => $item['expectedRevision'],
            ];
        }

        ksort($config);

        return $config;
    }

    /**
     * @param  TemplateItem  $expected
     */
    private function templateMatches(Template $template, array $expected): bool
    {
        return $template->status === $expected['status']
            && (is_array($template->metadata) ? $template->metadata : []) == $expected['metadata']
            && $this->modelTranslations($template, ['title', 'description']) == $expected['translations'];
    }

    /**
     * @param  ContentItem  $expected
     */
    private function contentMatches(ContentBlock $block, array $expected): bool
    {
        return $block->visibility === $expected['visibility']
            && (is_array($block->values) ? $block->values : []) == $expected['values']
            && (is_array($block->metadata) ? $block->metadata : []) == $expected['metadata']
            && $this->modelTranslations($block, ['values']) == $expected['translations']
            && ($block->status === ContentStatus::Published) === $expected['publish'];
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function modelTranslations(Template|ContentBlock $model, array $fields): array
    {
        $translations = [];

        foreach ($model->translations as $translation) {
            $locale = $translation->getAttribute('locale');

            if (! is_string($locale)) {
                continue;
            }

            foreach ($fields as $field) {
                $value = $translation->getAttribute($field);

                if ($value !== null) {
                    $translations[$locale][$field] = $value;
                }
            }
        }

        ksort($translations);

        return $translations;
    }
}
