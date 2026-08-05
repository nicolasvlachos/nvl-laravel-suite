<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

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

    /**
     * Register one uniquely keyed sitemap source.
     */
    public function register(SitemapSource $source, ?string $key = null): self
    {
        $key ??= $source::class;
        $key = trim($key);

        if ($key === '' || isset($this->sources[$key])) {
            throw new InvalidArgumentException(
                "Sitemap source key [{$key}] is empty or already registered.",
            );
        }

        $this->sources[$key] = $source;

        return $this;
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
