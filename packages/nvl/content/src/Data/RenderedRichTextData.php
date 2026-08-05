<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Explicit sanitized HTML projection; consumers choose how and where to render it.
 */
#[TypeScript]
final class RenderedRichTextData extends Data
{
    use DataTransform;

    public function __construct(public readonly string $html) {}
}
