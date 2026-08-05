<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One localized public navigation node and its visible children.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class NavigationItemData extends Data
{
    use DataTransform;

    /**
     * Create one localized navigation node.
     *
     * @param  list<NavigationItemData>  $children
     */
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $path,
        public readonly string $url,
        public readonly string $label,
        #[LiteralTypeScriptType('Array<Nvl.Pages.Data.NavigationItemData>')]
        public readonly array $children,
    ) {}
}
