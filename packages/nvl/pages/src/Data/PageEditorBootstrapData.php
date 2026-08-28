<?php

declare(strict_types=1);

namespace Nvl\Pages\Data;

use Nvl\Content\Data\ContentEditorData;
use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Data\OwnerMetafieldField;
use Nvl\Seo\Data\SeoProfileData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Complete package-owned bootstrap for one authorized Page editor.
 */
#[TypeScript]
final class PageEditorBootstrapData extends Data
{
    use DataTransform;

    /**
     * @param  list<OwnerMetafieldField>  $metafields
     * @param  list<string>  $pageKinds
     * @param  list<string>  $pageStatuses
     * @param  list<string>  $resourceAliases
     */
    public function __construct(
        public readonly PageData $page,
        public readonly ContentEditorData $content,
        public readonly ?SeoProfileData $seo,
        #[DataCollectionOf(OwnerMetafieldField::class)]
        public readonly array $metafields,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $pageKinds,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $pageStatuses,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $resourceAliases,
        public readonly int $maximumDepth,
    ) {}
}
