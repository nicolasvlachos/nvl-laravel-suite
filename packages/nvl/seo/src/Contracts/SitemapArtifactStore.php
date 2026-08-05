<?php

declare(strict_types=1);

namespace Nvl\Seo\Contracts;

/**
 * Persists complete sitemap XML artifacts outside the general cache store.
 */
interface SitemapArtifactStore
{
    /**
     * Persist one complete XML artifact or fail explicitly.
     */
    public function write(string $namespace, string $artifact, string $contents): void;

    /**
     * Read one complete XML artifact when it exists.
     */
    public function read(string $namespace, string $artifact): ?string;

    /**
     * Delete every artifact in one immutable build namespace.
     */
    public function deleteNamespace(string $namespace): void;
}
