<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Nvl\Content\Data\RenderedContentCompositionData;
use Nvl\Data\Traits\DataTransform;
use Nvl\Seo\Data\ResolvedSeoData;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Complete headless page response assembled from package-owned boundaries.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class ResolvedPageData extends Data
{
    use DataTransform;

    /**
     * Create one complete public headless page response.
     */
    public function __construct(
        public readonly PublicPageData $page,
        public readonly RenderedContentCompositionData $content,
        public readonly ResolvedSeoData $seo,
        public readonly ?PageResourceData $resource,
    ) {}
}
