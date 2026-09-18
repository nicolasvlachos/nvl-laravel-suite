<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use LogicException;
use Nvl\Seo\Contracts\SitemapArtifactStore;
use Nvl\Seo\Data\SitemapCacheIdentity;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Support\SeoConfiguration;
use Nvl\Seo\Support\SeoScope;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;
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
        return $this->capture($scope)->key;
    }

    /**
     * Return the immutable filesystem namespace for the current scope version.
     */
    public function namespace(string $scope): string
    {
        return $this->capture($scope)->namespace;
    }

    /** Capture tenant/site/origin/version facts for safe deferred work. */
    public function capture(string $scope): SitemapCacheIdentity
    {
        $scope = SeoScope::normalize($scope);
        $facts = $this->facts($scope);
        $base = $this->baseKey($facts);
        $version = $this->version($base.':version');
        $key = $base.':v'.$version;

        return new SitemapCacheIdentity(
            connection: $facts['connection'],
            tenantId: $facts['tenant'],
            site: $facts['site'],
            origin: $facts['origin'],
            scope: $scope,
            version: $version,
            key: $key,
            namespace: hash('sha256', $key),
        );
    }

    /**
     * Advance the scope version so completed artifacts become unreachable atomically.
     *
     * Cleanup failures are reported after invalidation because surfacing them
     * would make a committed profile write appear to have failed.
     */
    public function forget(string $scope): bool
    {
        return $this->forgetCaptured($this->capture($scope));
    }

    /** Invalidate exactly the identity captured with the committed mutation. */
    public function forgetCaptured(SitemapCacheIdentity $identity): bool
    {
        try {
            $base = $this->baseKey([
                'connection' => $identity->connection,
                'tenant' => $identity->tenantId,
                'site' => $identity->site,
                'origin' => $identity->origin,
                'scope' => $identity->scope,
            ]);
            $versionKey = $base.':version';
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
            $this->artifacts->deleteNamespace($identity->namespace);
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }

    /** @param array{connection:string,tenant:string,site:string,origin:string,scope:string} $facts */
    private function baseKey(array $facts): string
    {
        return SeoConfiguration::string('seo.sitemap.cache_key', 'nvl-seo:sitemap')
            .':'.hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    /** @return array{connection:string,tenant:string,site:string,origin:string,scope:string} */
    private function facts(string $scope): array
    {
        $container = Container::getInstance();
        $connection = (new SeoProfile)->getConnection()->getName() ?? 'default';

        if ($container->bound('config') && $container->make('config')->get('tenancy.enabled') === true) {
            $site = $container->make(TenantSiteContext::class);

            return [
                'connection' => $connection,
                'tenant' => $site->tenantId->value,
                'site' => $site->site,
                'origin' => rtrim($site->canonicalOrigin, '/'),
                'scope' => $scope,
            ];
        }

        $applicationUrl = config('app.url', 'http://localhost');
        $origin = SeoConfiguration::string('seo.site.base_url', is_string($applicationUrl) ? $applicationUrl : 'http://localhost');

        return [
            'connection' => $connection,
            'tenant' => '*',
            'site' => SeoScope::normalize($scope),
            'origin' => rtrim($origin, '/'),
            'scope' => $scope,
        ];
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
