<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Seo\Contracts\SitemapSource;

/**
 * Collects sitemap sources from the package and host application.
 */
final class SitemapRegistry
{
    /**
     * @var array<string, SitemapSource>
     */
    private array $sources = [];

    /** @var array<class-string<Model>, string> */
    private array $profileOwners = [];

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

        $this->sources[$key] = $source;

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

        return array_values($this->sources);
    }
}
