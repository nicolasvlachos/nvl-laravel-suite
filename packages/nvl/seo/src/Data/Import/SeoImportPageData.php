<?php

declare(strict_types=1);

namespace Nvl\Seo\Data\Import;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One deterministic page from a consumer-owned SEO import source.
 */
#[TypeScript]
final class SeoImportPageData extends Data
{
    /**
     * @param  DataCollection<int, SeoImportRecordData>  $items
     */
    public function __construct(
        #[DataCollectionOf(SeoImportRecordData::class)]
        public readonly DataCollection $items,
        public readonly ?string $nextCursor,
    ) {}
}
