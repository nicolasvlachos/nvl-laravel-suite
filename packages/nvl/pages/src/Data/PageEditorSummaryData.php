<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Nvl\Content\Data\ContentPlacementData;
use Nvl\Data\Traits\DataTransform;
use Nvl\Seo\Data\SeoProfileData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Bounded Page, Content, and SEO projection for management indexes.
 */
#[TypeScript]
final class PageEditorSummaryData extends Data
{
    use DataTransform;

    /**
     * @param  list<ContentPlacementData>  $placements
     */
    public function __construct(
        public readonly PageData $page,
        public readonly string $label,
        #[DataCollectionOf(ContentPlacementData::class)]
        public readonly array $placements,
        public readonly ?SeoProfileData $seo,
    ) {}
}
