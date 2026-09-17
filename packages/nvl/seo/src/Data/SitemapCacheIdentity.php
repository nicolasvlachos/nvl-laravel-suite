<?php

declare(strict_types=1);

namespace Nvl\Seo\Data;

/** Captures every immutable fact used by one sitemap build and invalidation. */
final readonly class SitemapCacheIdentity
{
    public function __construct(
        public string $connection,
        public string $tenantId,
        public string $site,
        public string $origin,
        public string $scope,
        public int $version,
        public string $key,
        public string $namespace,
    ) {}
}
