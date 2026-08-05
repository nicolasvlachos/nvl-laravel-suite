<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Nvl\Content\Enums\ContentLinkRelationship;
use Nvl\Content\Enums\ContentLinkTarget;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Safe semantic projection of one navigational content link.
 */
#[TypeScript]
final class RenderedContentLinkData extends Data
{
    use DataTransform;

    /**
     * @param  list<ContentLinkRelationship>  $rel
     */
    public function __construct(
        public readonly string $label,
        public readonly string $href,
        public readonly ?string $title,
        public readonly ContentLinkTarget $target,
        #[LiteralTypeScriptType('Array<Nvl.Content.Enums.ContentLinkRelationship>')]
        public readonly array $rel,
    ) {}
}
