<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Complete, frontend-neutral metadata resolved for one page locale.
 */
#[TypeScript]
final class ResolvedSeoData extends Data
{
    use DataTransform;

    /**
     * @param  array<string, string>  $alternates
     * @param  array<string, string>  $openGraph
     * @param  list<string>  $openGraphLocales
     * @param  array<string, string>  $twitter
     * @param  list<array<string, mixed>>  $structuredData
     */
    public function __construct(
        public readonly string $locale,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $canonicalUrl,
        public readonly string $robots,
        #[LiteralTypeScriptType('Record<string, string>')]
        public readonly array $alternates,
        #[LiteralTypeScriptType('Record<string, string>')]
        public readonly array $openGraph,
        #[LiteralTypeScriptType('Array<string>')]
        public readonly array $openGraphLocales,
        #[LiteralTypeScriptType('Record<string, string>')]
        public readonly array $twitter,
        #[LiteralTypeScriptType('Array<Record<string, unknown>>')]
        public readonly array $structuredData,
    ) {}
}
