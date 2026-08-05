<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Content\Enums\ContentHeadingLevel;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Semantic heading projection with localized copy and a structural heading level.
 */
#[TypeScript]
final class RenderedContentHeadingData extends Data
{
    use DataTransform;

    public function __construct(
        public readonly ?string $eyebrow,
        public readonly string $title,
        public readonly RenderedRichTextData|string|null $description,
        public readonly ContentHeadingLevel $level,
    ) {}
}
