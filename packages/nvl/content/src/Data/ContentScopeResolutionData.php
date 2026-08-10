<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Deterministic localized values selected through ordered scope fallback.
 */
#[TypeScript]
final class ContentScopeResolutionData extends Data
{
    /**
     * @param  list<ContentScopeData>  $scopes
     * @param  array<string, array<string, mixed>>  $values
     * @param  array<string, string>  $sources
     */
    public function __construct(
        public readonly string $locale,
        #[DataCollectionOf(ContentScopeData::class)]
        public readonly array $scopes,
        public readonly array $values,
        public readonly array $sources,
        public readonly int $matched,
        public readonly int $limit,
    ) {}
}
