<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Media\Data\Display\PublicMedia;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Safe semantic projection of an image asset and its content-owned presentation metadata.
 */
#[TypeScript]
final class RenderedContentImageData extends Data
{
    use DataTransform;

    public function __construct(
        #[LiteralTypeScriptType(
            'Nvl.Media.Data.Display.PublicMedia | Nvl.Content.Data.RenderedPrivateMediaData | null',
        )]
        public readonly PublicMedia|RenderedPrivateMediaData|null $media,
        public readonly ?string $alt,
        public readonly ?string $title,
        public readonly RenderedRichTextData|string|null $caption,
        public readonly ?string $credit,
        public readonly bool $decorative,
        public readonly ?float $focalX,
        public readonly ?float $focalY,
    ) {}
}
