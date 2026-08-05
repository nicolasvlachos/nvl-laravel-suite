<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Content\Enums\ContentStatus;
use Nvl\Content\Enums\ContentVisibility;
use Nvl\Content\Models\ContentBlock;
use Nvl\Content\Support\ContentArrays;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Privileged editable block representation.
 */
#[TypeScript]
final class ContentBlockData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $translations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $definition,
        public readonly string $key,
        public readonly string $scope,
        public readonly string $scopeKey,
        public readonly ContentStatus $status,
        public readonly ContentVisibility $visibility,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $values,
        #[LiteralTypeScriptType('Record<string, Record<string, unknown>>')]
        public readonly array $translations,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata,
        public readonly int $definitionVersion,
        public readonly int $revision,
        public readonly ?string $publishedAt,
    ) {}

    public static function fromModel(ContentBlock $block): self
    {
        $block->loadMissing(['definition', 'translations']);
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

        return new self(
            id: $block->id,
            definition: $block->definition->key,
            key: $block->key,
            scope: $block->scope,
            scopeKey: $block->scope_key,
            status: $block->status,
            visibility: $block->visibility,
            values: is_array($block->values) ? $block->values : [],
            translations: $translations,
            metadata: is_array($block->metadata) ? $block->metadata : [],
            definitionVersion: $block->definition_version,
            revision: $block->revision,
            publishedAt: $block->published_at?->toAtomString(),
        );
    }
}
