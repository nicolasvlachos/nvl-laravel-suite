<?php

declare(strict_types=1);

namespace Nvl\Templates\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Optional PDF margin overrides expressed in millimetres.
 */
#[TypeScript]
final class PdfMargins extends Data
{
    use DataTransform;

    public function __construct(
        public readonly ?float $left = null,
        public readonly ?float $right = null,
        public readonly ?float $top = null,
        public readonly ?float $bottom = null,
        public readonly ?float $header = null,
        public readonly ?float $footer = null,
    ) {}
}
