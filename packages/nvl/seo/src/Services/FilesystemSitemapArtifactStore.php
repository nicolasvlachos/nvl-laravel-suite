<?php

declare(strict_types=1);

namespace Nvl\Seo\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use LogicException;
use Nvl\Seo\Contracts\SitemapArtifactStore;

/**
 * Stores sitemap XML artifacts on a configurable Laravel filesystem disk.
 */
final readonly class FilesystemSitemapArtifactStore implements SitemapArtifactStore
{
    /**
     * Create the filesystem-backed artifact store.
     */
    public function __construct(
        private FilesystemManager $filesystems,
        private Repository $config,
    ) {}

    /**
     * Persist one complete XML artifact or fail explicitly.
     */
    public function write(string $namespace, string $artifact, string $contents): void
    {
        $path = $this->path($namespace, $artifact);
        $disk = $this->disk();

        if (! $disk->put($path, $contents, ['visibility' => 'private'])
            || ! $disk->exists($path)
            || $disk->size($path) !== strlen($contents)) {
            throw new LogicException("Sitemap artifact [{$artifact}] could not be persisted completely.");
        }
    }

    /**
     * Read one complete XML artifact when it exists.
     */
    public function read(string $namespace, string $artifact): ?string
    {
        $path = $this->path($namespace, $artifact);
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * Delete every artifact in one immutable build namespace.
     */
    public function deleteNamespace(string $namespace): void
    {
        $directory = $this->directory().'/'.$this->validatedNamespace($namespace);

        if (! $this->disk()->deleteDirectory($directory)) {
            throw new LogicException("Sitemap artifact namespace [{$namespace}] could not be deleted.");
        }
    }

    /**
     * Resolve the configured artifact filesystem.
     */
    private function disk(): Filesystem
    {
        $disk = $this->config->get('seo.sitemap.disk');

        return is_string($disk) && $disk !== ''
            ? $this->filesystems->disk($disk)
            : $this->filesystems->disk();
    }

    /**
     * Return a safe artifact path.
     */
    private function path(string $namespace, string $artifact): string
    {
        if (preg_match('/^(?:index|chunk-[1-9][0-9]*)\.xml$/', $artifact) !== 1) {
            throw new LogicException("Sitemap artifact name [{$artifact}] is invalid.");
        }

        return $this->directory().'/'.$this->validatedNamespace($namespace).'/'.$artifact;
    }

    /**
     * Return the configured safe root directory.
     */
    private function directory(): string
    {
        $directory = $this->config->get('seo.sitemap.directory', 'nvl-seo/sitemaps');

        if (! is_string($directory)
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]*$#', $directory) !== 1
            || str_contains($directory, '..')) {
            throw new LogicException('seo.sitemap.directory must be a safe relative filesystem path.');
        }

        return trim($directory, '/');
    }

    /**
     * Return a validated immutable build namespace.
     */
    private function validatedNamespace(string $namespace): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $namespace) !== 1) {
            throw new LogicException("Sitemap artifact namespace [{$namespace}] is invalid.");
        }

        return $namespace;
    }
}
