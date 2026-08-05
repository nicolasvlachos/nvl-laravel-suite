<?php

declare(strict_types=1);

namespace Nvl\Seo\Data\Import;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Reports one completed, bounded SEO import page.
 */
#[TypeScript]
final class SeoImportResultData extends Data
{
    /**
     * Create one import-page result.
     */
    public function __construct(
        public readonly int $processed,
        public readonly ?string $nextCursor,
    ) {}
}
