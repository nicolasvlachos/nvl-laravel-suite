<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Contracts\Cache\Repository;
use LogicException;
use Nvl\Seo\Contracts\SitemapArtifactStore;
use Nvl\Seo\Support\SeoConfiguration;
use Nvl\Seo\Support\SeoScope;
use Throwable;

/**
 * Owns sitemap cache key construction and invalidation.
 */
final readonly class SitemapCache
{
    /**
     * Create the versioned sitemap cache-key manager.
     */
    public function __construct(
        private Repository $cache,
        private SitemapArtifactStore $artifacts,
    ) {}

    /**
     * Return the current versioned artifact key prefix for one scope.
     */
    public function key(string $scope): string
    {
        $base = $this->baseKey($scope);
        $version = $this->version($base.':version');

        return $base.':v'.$version;
    }

    /**
     * Return the immutable filesystem namespace for the current scope version.
     */
    public function namespace(string $scope): string
    {
        return hash('sha256', $this->key($scope));
    }

    /**
     * Advance the scope version so completed artifacts become unreachable atomically.
     *
     * Cleanup failures are reported after invalidation because surfacing them
     * would make a committed profile write appear to have failed.
     */
    public function forget(string $scope): bool
    {
        try {
            $base = $this->baseKey($scope);
            $versionKey = $base.':version';
            $namespace = $this->namespace($scope);
            $this->cache->add($versionKey, 1);
            $version = $this->cache->increment($versionKey);

            if (! is_int($version) || $version < 2) {
                throw new LogicException(
                    'The sitemap cache store cannot atomically advance its version.',
                );
            }
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        try {
            $this->artifacts->deleteNamespace($namespace);
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }

    private function baseKey(string $scope): string
    {
        $applicationUrl = config('app.url', 'http://localhost');
        $siteUrl = SeoConfiguration::string(
            'seo.site.base_url',
            is_string($applicationUrl) ? $applicationUrl : 'http://localhost',
        );

        return SeoConfiguration::string('seo.sitemap.cache_key', 'nvl-seo:sitemap')
            .':'.hash('sha256', rtrim($siteUrl, '/'))
            .':'.SeoScope::normalize($scope);
    }

    private function version(string $key): int
    {
        $version = $this->cache->get($key);

        if ($version === null) {
            return 1;
        }

        if (! is_int($version) || $version < 1) {
            throw new LogicException(
                'The sitemap cache version contains an invalid value.',
            );
        }

        return $version;
    }
}
