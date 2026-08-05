<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Validated context supplied to a registered dynamic resource handler.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[Hidden]
final class PageResourceRequestData extends Data
{
    use DataTransform;

    /**
     * Create validated dynamic resource request context.
     *
     * @param  array<string, string>  $parameters
     */
    public function __construct(
        public readonly string $pageId,
        public readonly string $site,
        public readonly string $locale,
        public readonly array $parameters,
    ) {}
}
