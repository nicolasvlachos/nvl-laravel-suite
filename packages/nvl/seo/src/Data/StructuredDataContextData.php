<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Supplies resolved page and resource facts to structured-data providers.
 */
#[TypeScript]
final class StructuredDataContextData extends Data
{
    /**
     * Create the immutable resource-aware JSON-LD provider context.
     */
    public function __construct(
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly string $profileId,
        public readonly string $locale,
        public readonly string $scope,
        public readonly ?string $canonicalUrl,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $imageUrl,
        public readonly string $siteName,
        public readonly string $siteUrl,
    ) {}
}
