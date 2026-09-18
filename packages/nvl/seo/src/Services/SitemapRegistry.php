<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Seo\Contracts\SitemapSource;
use Nvl\Seo\Contracts\TenantSafeSitemapSource;
use Nvl\Tenancy\Services\TenantResourceRegistry;

/**
 * Collects sitemap sources from the package and host application.
 */
final class SitemapRegistry
{
    /**
     * @var array<string, array{class:class-string<SitemapSource>|null,instance:SitemapSource|null}>
     */
    private array $sources = [];

    /** @var array<class-string<Model>, string> */
    private array $profileOwners = [];

    public function __construct(
        private readonly Container $container,
        private readonly TenantResourceRegistry $tenantResources,
    ) {}

    /**
     * Register one uniquely keyed source and the SEO owner types it exclusively projects.
     *
     * @param  list<string>  $ownerTypes  Model class names validated before registration.
     */
    public function register(SitemapSource $source, ?string $key = null, array $ownerTypes = []): self
    {
        $key ??= $source::class;
        $key = trim($key);

        if ($key === '' || isset($this->sources[$key])) {
            throw new InvalidArgumentException(
                "Sitemap source key [{$key}] is empty or already registered.",
            );
        }

        foreach ($ownerTypes as $ownerType) {
            if (! is_a($ownerType, Model::class, true)
                || isset($this->profileOwners[$ownerType])) {
                throw new InvalidArgumentException(
                    "Sitemap SEO owner type [{$ownerType}] is invalid or already assigned.",
                );
            }
        }

        foreach ($ownerTypes as $ownerType) {
            $this->profileOwners[$ownerType] = $key;
        }

        $this->sources[$key] = ['class' => null, 'instance' => $source];

        return $this;
    }

    /**
     * Register an immutable source declaration resolved freshly in each active tenant scope.
     *
     * @param  class-string<SitemapSource>  $sourceClass
     * @param  list<class-string<Model>>  $ownerTypes
     */
    public function registerType(string $sourceClass, ?string $key = null, array $ownerTypes = []): self
    {
        if ($this->container->make('config')->get('tenancy.enabled') === true
            && ! is_a($sourceClass, TenantSafeSitemapSource::class, true)) {
            throw new InvalidArgumentException(
                "Tenant sitemap source [{$sourceClass}] must declare its tenant-safe resource capability.",
            );
        }

        $key ??= $sourceClass;
        $key = trim($key);
        if ($key === '' || isset($this->sources[$key])) {
            throw new InvalidArgumentException("Sitemap source key [{$key}] is empty or already registered.");
        }

        foreach ($ownerTypes as $ownerType) {
            if (isset($this->profileOwners[$ownerType])) {
                throw new InvalidArgumentException("Sitemap SEO owner type [{$ownerType}] is invalid or already assigned.");
            }
        }

        foreach ($ownerTypes as $ownerType) {
            $this->profileOwners[$ownerType] = $key;
        }

        $this->sources[$key] = ['class' => $sourceClass, 'instance' => null];

        return $this;
    }

    /**
     * Resolve current morph aliases and model names owned by registered sources.
     *
     * @return list<string>
     */
    public function ownedProfileTypes(): array
    {
        $types = array_keys($this->profileOwners);

        foreach (Relation::morphMap() as $alias => $ownerClass) {
            if (isset($this->profileOwners[$ownerClass])) {
                $types[] = (string) $alias;
            }
        }

        $types = array_values(array_unique($types));
        sort($types, SORT_STRING);

        return $types;
    }

    /**
     * @return list<SitemapSource>
     */
    public function all(): array
    {
        ksort($this->sources);

        $resolved = [];
        $enabled = $this->container->make('config')->get('tenancy.enabled') === true;
        foreach ($this->sources as $key => $declaration) {
            if ($enabled && $declaration['class'] === null) {
                throw new InvalidArgumentException(
                    "Tenant sitemap source [{$key}] must be registered by class with registerType().",
                );
            }

            $source = $declaration['class'] === null
                ? $declaration['instance']
                : $this->container->build($declaration['class']);
            if (! $source instanceof SitemapSource) {
                throw new InvalidArgumentException("Sitemap source [{$key}] could not be resolved.");
            }
            if ($enabled) {
                if (! $source instanceof TenantSafeSitemapSource || $source->tenantResources() === []) {
                    throw new InvalidArgumentException("Tenant sitemap source [{$key}] has no resource capability.");
                }
                foreach ($source->tenantResources() as $resource) {
                    if ($resource === '') {
                        throw new InvalidArgumentException("Tenant sitemap source [{$key}] has an invalid resource capability.");
                    }

                    $this->tenantResources->get($resource);
                }
            }
            $resolved[] = $source;
        }

        return $resolved;
    }
}
