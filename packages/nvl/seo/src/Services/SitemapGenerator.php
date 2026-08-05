<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use LogicException;
use Nvl\Seo\Contracts\SitemapArtifactStore;
use Nvl\Seo\Data\SitemapEntry;
use Nvl\Seo\Support\SeoConfiguration;
use Nvl\Seo\Support\SeoRouteConfiguration;
use Nvl\Seo\Support\SeoScope;
use OutOfBoundsException;
use XMLWriter;

/**
 * Produces bounded, cached XML sitemap artifacts from registered iterable sources.
 */
final readonly class SitemapGenerator
{
    private const MAXIMUM_STANDARD_BYTES = 52_428_800;

    private const MAXIMUM_INDEX_ENTRIES = 50_000;

    private const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>';

    private const URLSET_OPEN = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';

    private const URLSET_CLOSE = '</urlset>';

    /**
     * Create the sitemap artifact generator.
     */
    public function __construct(
        private SitemapRegistry $sources,
        private SitemapCache $cacheKeys,
        private Repository $cache,
        private SitemapArtifactStore $artifacts,
        private AbsoluteUrl $urls,
        private ?SitemapLocationPolicy $locations = null,
    ) {}

    /**
     * Return a sitemap index when required, otherwise the only URL chunk.
     */
    public function generate(?string $scope = null): string
    {
        $scope = SeoScope::normalize($scope);

        if ($this->cacheSeconds() === 0) {
            $firstChunk = null;
            $artifacts = $this->buildArtifacts(
                $scope,
                static function (int $chunk, string $xml) use (&$firstChunk): void {
                    if ($chunk === 1) {
                        $firstChunk = $xml;
                    }
                },
            );

            return $artifacts['index']
                ?? $firstChunk
                ?? throw new LogicException('The sitemap build produced no URL-set artifact.');
        }

        return $this->cachedPrimaryArtifact($scope);
    }

    /**
     * Return one one-based sitemap chunk.
     */
    public function generateChunk(int $chunk, ?string $scope = null): string
    {
        $scope = SeoScope::normalize($scope);

        if ($this->cacheSeconds() === 0) {
            if ($chunk < 1) {
                throw new OutOfBoundsException("Sitemap chunk [{$chunk}] does not exist.");
            }

            $selectedChunk = null;
            $artifacts = $this->buildArtifacts(
                $scope,
                static function (int $currentChunk, string $xml) use (
                    $chunk,
                    &$selectedChunk,
                ): void {
                    if ($currentChunk === $chunk) {
                        $selectedChunk = $xml;
                    }
                },
            );

            if ($chunk > $artifacts['chunkCount'] || ! is_string($selectedChunk)) {
                throw new OutOfBoundsException("Sitemap chunk [{$chunk}] does not exist.");
            }

            return $selectedChunk;
        }

        return $this->cachedChunkArtifact($scope, $chunk);
    }

    /**
     * Return the number of generated sitemap chunks without rescanning warm sources.
     */
    public function chunkCount(?string $scope = null): int
    {
        $scope = SeoScope::normalize($scope);

        if ($this->cacheSeconds() === 0) {
            return $this->buildArtifacts(
                $scope,
                static function (int $chunk, string $xml): void {},
            )['chunkCount'];
        }

        return $this->ensureCachedArtifacts($scope)['chunks'];
    }

    /**
     * Ensure a complete artifact set is visible under the current cache version.
     *
     * @return array{chunks:int,namespace:string}
     */
    private function ensureCachedArtifacts(string $scope): array
    {
        $key = $this->cacheKeys->key($scope);
        $namespace = hash('sha256', $key);
        $manifest = $this->cachedManifest($key);

        if ($manifest !== null) {
            return [...$manifest, 'namespace' => $namespace];
        }

        $build = function () use ($key, $namespace, $scope): array {
            $manifest = $this->cachedManifest($key);

            if ($manifest !== null) {
                return $manifest;
            }

            $artifacts = $this->buildArtifacts(
                $scope,
                function (int $chunk, string $xml) use ($namespace): void {
                    $this->artifacts->write($namespace, "chunk-{$chunk}.xml", $xml);
                },
            );

            if ($artifacts['index'] !== null) {
                $this->artifacts->write(
                    $namespace,
                    'index.xml',
                    $artifacts['index'],
                );
            }

            $manifest = ['chunks' => $artifacts['chunkCount']];
            $published = $this->cache->put(
                $this->cacheKey($key, 'manifest'),
                $manifest,
                $this->cacheSeconds(),
            );

            if (! $published) {
                throw new LogicException('The completed sitemap manifest could not be published.');
            }

            return $manifest;
        };
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException(
                'Cached sitemap generation requires a cache store with atomic lock support.',
            );
        }

        $manifest = $store
            ->lock(
                $this->cacheKey($key, 'build-lock'),
                SeoConfiguration::positiveInteger('seo.sitemap.lock_seconds', 60),
            )
            ->block(
                SeoConfiguration::positiveInteger('seo.sitemap.lock_wait_seconds', 10),
                $build,
            );

        if (! is_array($manifest)
            || ! isset($manifest['chunks'])
            || ! is_int($manifest['chunks'])
            || $manifest['chunks'] < 1
            || $manifest['chunks'] > self::MAXIMUM_INDEX_ENTRIES) {
            throw new LogicException('The sitemap build lock returned an invalid manifest.');
        }

        return [
            'chunks' => $manifest['chunks'],
            'namespace' => $namespace,
        ];
    }

    /**
     * Build every chunk in one source pass and optionally emit completed chunks immediately.
     *
     * @param  Closure(int, string): void  $sink
     * @return array{chunkCount:int,index:string|null}
     */
    private function buildArtifacts(string $scope, Closure $sink): array
    {
        $entries = [];
        $entryBytes = 0;
        $chunk = 0;
        $maximumUrls = $this->maximumUrls();
        $maximumBytes = $this->maximumBytes();
        $wrapperBytes = strlen(self::XML_HEADER.self::URLSET_OPEN.self::URLSET_CLOSE);

        foreach ($this->entries($scope) as $entry) {
            $xml = $this->renderEntry($entry);
            $xmlBytes = strlen($xml);

            if ($wrapperBytes + $xmlBytes > $maximumBytes) {
                throw new LogicException(
                    "One sitemap entry exceeds the configured {$maximumBytes}-byte artifact limit.",
                );
            }

            if ($entries !== []
                && (count($entries) >= $maximumUrls
                    || $wrapperBytes + $entryBytes + $xmlBytes > $maximumBytes)) {
                $this->emitChunk($entries, ++$chunk, $sink);
                $entries = [];
                $entryBytes = 0;
            }

            $entries[] = $xml;
            $entryBytes += $xmlBytes;
        }

        $this->emitChunk($entries, ++$chunk, $sink);

        if ($chunk > self::MAXIMUM_INDEX_ENTRIES) {
            throw new LogicException(
                'A sitemap index may not contain more than 50,000 sitemap artifacts.',
            );
        }

        if ($chunk > 1 && ! (bool) config('seo.sitemap.index_enabled', true)) {
            throw new LogicException(
                'The sitemap requires an index, but seo.sitemap.index_enabled is false.',
            );
        }

        $index = $chunk > 1 ? $this->buildIndex($scope, $chunk) : null;

        if ($index !== null && strlen($index) > $maximumBytes) {
            throw new LogicException(
                "The sitemap index exceeds the configured {$maximumBytes}-byte artifact limit.",
            );
        }

        return [
            'chunkCount' => $chunk,
            'index' => $index,
        ];
    }

    /**
     * Emit one complete URL-set document to a sink or the inline result.
     *
     * @param  list<string>  $entries
     * @param  Closure(int, string): void  $sink
     */
    private function emitChunk(
        array $entries,
        int $chunk,
        Closure $sink,
    ): void {
        $xml = self::XML_HEADER.self::URLSET_OPEN.implode('', $entries).self::URLSET_CLOSE;
        $sink($chunk, $xml);
    }

    /**
     * Build one sitemap index document.
     */
    private function buildIndex(string $scope, int $chunks): string
    {
        if ((bool) config('seo.routes.enabled', false)
            && ! in_array($scope, SeoScope::publicSitemapScopes(), true)) {
            throw new LogicException(
                "Sitemap scope [{$scope}] must be listed in seo.routes.sitemap_scopes before its index can be served.",
            );
        }

        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $path = SeoRouteConfiguration::sitemapChunkPath();
        $defaultScope = SeoScope::normalize();

        for ($chunk = 1; $chunk <= $chunks; $chunk++) {
            $chunkPath = str_replace('{chunk}', (string) $chunk, $path);

            if ($scope !== $defaultScope) {
                $chunkPath .= '?scope='.rawurlencode($scope);
            }

            $url = $this->urls->resolve($chunkPath);

            if ($url === null) {
                throw new LogicException(
                    "The sitemap chunk path for chunk [{$chunk}] cannot be resolved.",
                );
            }

            $writer->startElement('sitemap');
            $writer->writeElement('loc', $url);
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Render one URL entry without a surrounding document.
     */
    private function renderEntry(SitemapEntry $entry): string
    {
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->startElement('url');
        $writer->writeElement('loc', $entry->url);

        if ($entry->lastModified !== null) {
            $writer->writeElement('lastmod', $entry->lastModified->format(DATE_ATOM));
        }

        if ($entry->changeFrequency !== null) {
            $writer->writeElement('changefreq', $entry->changeFrequency->value);
        }

        if ($entry->priority !== null) {
            $writer->writeElement('priority', $entry->priority);
        }

        foreach ($entry->alternates as $locale => $url) {
            $writer->startElement('xhtml:link');
            $writer->writeAttribute('rel', 'alternate');
            $writer->writeAttribute('hreflang', $locale);
            $writer->writeAttribute('href', $url);
            $writer->endElement();
        }

        $writer->endElement();

        return $writer->outputMemory();
    }

    /**
     * Yield entries deterministically from every registered source.
     *
     * @return \Generator<int, SitemapEntry>
     */
    private function entries(string $scope): \Generator
    {
        $sitemapUrl = $this->urls->resolve(SeoRouteConfiguration::sitemapPath());

        if ($sitemapUrl === null) {
            throw new LogicException('The configured sitemap URL cannot be resolved.');
        }

        $locations = $this->locationPolicy();

        foreach ($this->sources->all() as $source) {
            foreach ($source->entries($scope) as $entry) {
                $locations->assertAllowed($entry->url, $sitemapUrl);

                yield $entry;
            }
        }
    }

    /**
     * Return the cached primary document, rebuilding once when an artifact disappeared.
     */
    private function cachedPrimaryArtifact(string $scope): string
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $manifest = $this->ensureCachedArtifacts($scope);
            $artifactName = $manifest['chunks'] > 1 ? 'index.xml' : 'chunk-1.xml';
            $artifact = $this->artifacts->read($manifest['namespace'], $artifactName);

            if ($artifact !== null) {
                return $artifact;
            }

            if ($attempt === 0 && $this->cacheKeys->forget($scope)) {
                continue;
            }

            throw new LogicException(
                "Sitemap artifact [{$artifactName}] is missing from its completed manifest.",
            );
        }

        throw new LogicException('The sitemap primary artifact could not be recovered.');
    }

    /**
     * Return one cached chunk, rebuilding once when an artifact disappeared.
     */
    private function cachedChunkArtifact(string $scope, int $chunk): string
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $manifest = $this->ensureCachedArtifacts($scope);

            if ($chunk < 1 || $chunk > $manifest['chunks']) {
                throw new OutOfBoundsException("Sitemap chunk [{$chunk}] does not exist.");
            }

            $artifactName = "chunk-{$chunk}.xml";
            $artifact = $this->artifacts->read($manifest['namespace'], $artifactName);

            if ($artifact !== null) {
                return $artifact;
            }

            if ($attempt === 0 && $this->cacheKeys->forget($scope)) {
                continue;
            }

            throw new LogicException(
                "Sitemap artifact [{$artifactName}] is missing from its completed manifest.",
            );
        }

        throw new LogicException("Sitemap chunk [{$chunk}] could not be recovered.");
    }

    /**
     * Return a valid completed manifest when present.
     *
     * @return array{chunks:int}|null
     */
    private function cachedManifest(string $key): ?array
    {
        $manifest = $this->cache->get($this->cacheKey($key, 'manifest'));

        if (! is_array($manifest)
            || ! isset($manifest['chunks'])
            || ! is_int($manifest['chunks'])
            || $manifest['chunks'] < 1
            || $manifest['chunks'] > self::MAXIMUM_INDEX_ENTRIES) {
            return null;
        }

        return ['chunks' => $manifest['chunks']];
    }

    /**
     * Return a versioned artifact cache key.
     */
    private function cacheKey(string $key, string $suffix): string
    {
        return $key.':'.$suffix;
    }

    /**
     * Return the configured cache duration.
     */
    private function cacheSeconds(): int
    {
        return SeoConfiguration::nonNegativeInteger('seo.sitemap.cache_seconds', 3600);
    }

    /**
     * Return the portable URL count limit.
     */
    private function maximumUrls(): int
    {
        return min(
            50_000,
            SeoConfiguration::positiveInteger('seo.sitemap.max_urls', 50_000),
        );
    }

    /**
     * Return the portable uncompressed XML byte limit.
     */
    private function maximumBytes(): int
    {
        return min(
            self::MAXIMUM_STANDARD_BYTES,
            SeoConfiguration::positiveInteger(
                'seo.sitemap.max_bytes',
                self::MAXIMUM_STANDARD_BYTES,
            ),
        );
    }

    /**
     * Return the shared sitemap location policy.
     */
    private function locationPolicy(): SitemapLocationPolicy
    {
        return $this->locations ?? new SitemapLocationPolicy;
    }
}
