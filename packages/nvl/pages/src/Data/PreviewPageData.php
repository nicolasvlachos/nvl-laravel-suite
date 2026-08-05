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
 * Authorized management preview containing page state and rendered composition.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class PreviewPageData extends Data
{
    use DataTransform;

    /**
     * Create one complete authorized page preview.
     */
    public function __construct(
        public readonly PageData $page,
        public readonly RenderedContentCompositionData $content,
        public readonly ResolvedSeoData $seo,
        public readonly ?PageResourceData $resource,
    ) {}
}
