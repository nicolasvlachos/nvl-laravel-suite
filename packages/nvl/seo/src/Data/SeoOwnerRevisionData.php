<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lightweight optimistic revision identity for one owner's scoped SEO profile.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class SeoOwnerRevisionData extends Data
{
    /**
     * Create one owner-centric SEO revision projection.
     */
    public function __construct(
        public readonly string $ownerAlias,
        public readonly string $ownerId,
        public readonly string $scope,
        public readonly ?string $profileId,
        public readonly int $revision,
    ) {}
}
