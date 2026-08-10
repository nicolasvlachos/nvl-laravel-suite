<?php

declare(strict_types=1);

namespace Nvl\Content\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One exact Content scope in caller-defined fallback order.
 */
#[TypeScript]
final class ContentScopeData extends Data
{
    public function __construct(
        public readonly string $scope,
        public readonly string $scopeKey,
    ) {}
}
