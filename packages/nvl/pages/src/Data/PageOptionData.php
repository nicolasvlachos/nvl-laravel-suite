<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Pages\Enums\PageKind;
use Nvl\Pages\Enums\PageStatus;
use Nvl\Pages\Models\Page;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Minimal localized Page identity for bounded management selectors.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PageOptionData extends Data
{
    use DataTransform;

    /**
     * Create one localized Page option.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $label,
        public readonly string $path,
        public readonly PageKind $kind,
        public readonly PageStatus $status,
        public readonly int $revision,
    ) {}

    /**
     * Build an option from one already eager-loaded Page.
     */
    public static function fromModel(Page $page, string $locale): self
    {
        $page->loadMissing('translations');
        $label = trim($page->displayTitle($locale));

        return new self(
            id: $page->id,
            key: $page->key,
            label: $label !== '' ? $label : $page->key,
            path: $page->path,
            kind: $page->kind,
            status: $page->status,
            revision: $page->revision,
        );
    }
}
