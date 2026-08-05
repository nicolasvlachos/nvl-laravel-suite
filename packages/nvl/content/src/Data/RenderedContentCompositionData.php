<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Complete block composition grouped into stable regions.
 */
#[TypeScript]
final class RenderedContentCompositionData extends Data
{
    use DataTransform;

    /**
     * @param  list<RenderedContentBlockData>  $blocks
     * @param  array<string, list<RenderedContentBlockData>>  $regions
     */
    public function __construct(
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly string $group,
        public readonly string $locale,
        #[DataCollectionOf(RenderedContentBlockData::class)]
        public readonly array $blocks,
        #[LiteralTypeScriptType('Record<string, Array<Nvl.Content.Data.RenderedContentBlockData>>')]
        public readonly array $regions,
        public readonly string $version,
    ) {}

    public function firstValue(string $path): mixed
    {
        foreach ($this->flattenedBlocks() as $block) {
            $value = data_get($block->values, $path);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolve `placement-key.field.path`, falling back to the first matching field path.
     */
    public function value(string $path): mixed
    {
        [$blockKey, $fieldPath] = array_pad(explode('.', $path, 2), 2, null);

        if ($fieldPath !== null) {
            foreach ($this->flattenedBlocks() as $block) {
                if ($block->key === $blockKey) {
                    return data_get($block->values, $fieldPath);
                }
            }
        }

        return $this->firstValue($path);
    }

    /**
     * @return list<RenderedContentBlockData>
     */
    private function flattenedBlocks(): array
    {
        $flattened = [];
        $append = function (RenderedContentBlockData $block) use (&$append, &$flattened): void {
            $flattened[] = $block;

            foreach ($block->children as $child) {
                $append($child);
            }
        };

        foreach ($this->blocks as $block) {
            $append($block);
        }

        return $flattened;
    }
}
