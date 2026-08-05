<?php

declare(strict_types=1);

namespace Nvl\Content\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Publishes the safe default Blade starting point to a guarded custom directory.
 */
final class PublishContentViewsCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:content:views:publish
        {--path=resources/views/vendor/nvl-content : Destination relative to the application root}
        {--force : Replace existing files}';

    /** @var string */
    protected $description = 'Publish NVL Content Blade views to an allowed application path';

    public function handle(Filesystem $files): int
    {
        $path = $this->option('path');

        if (! is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException('The content view destination cannot be empty.');
        }

        $destination = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : base_path($path);
        $destination = $this->normalize($destination);
        $this->assertAllowed($destination);

        if ($files->exists($destination) && ! $files->isDirectory($destination)) {
            throw new InvalidArgumentException(
                "Content view destination [{$destination}] must be a directory.",
            );
        }

        $source = realpath(__DIR__.'/../../resources/views');

        if ($source === false) {
            throw new InvalidArgumentException('The package view source is unavailable.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
        );
        $copied = 0;

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($source)), DIRECTORY_SEPARATOR);
            $target = $destination.DIRECTORY_SEPARATOR.$relative;
            $this->assertAllowed($target);

            if ($files->exists($target) && ! $this->option('force')) {
                $this->warn("Skipped existing [{$target}].");

                continue;
            }

            $files->ensureDirectoryExists(dirname($target));
            $files->copy($file->getPathname(), $target);
            $copied++;
        }

        $this->info("Published {$copied} Content view files to [{$destination}].");

        return self::SUCCESS;
    }

    private function assertAllowed(string $destination): void
    {
        $roots = config('content.view_publishing.allowed_roots', [resource_path('views')]);

        if (! is_array($roots) || $roots === []) {
            throw new InvalidArgumentException(
                'content.view_publishing.allowed_roots must contain paths.',
            );
        }

        foreach ($roots as $root) {
            if (! is_string($root)) {
                continue;
            }

            $normalizedRoot = $this->normalize($root);

            if ($destination === $normalizedRoot
                || str_starts_with($destination, $normalizedRoot.DIRECTORY_SEPARATOR)) {
                $this->assertExistingAncestorIsSafe($destination, $normalizedRoot);

                return;
            }
        }

        throw new InvalidArgumentException(
            "Content view destination [{$destination}] escapes its allowed roots.",
        );
    }

    private function assertExistingAncestorIsSafe(
        string $destination,
        string $root,
    ): void {
        if (is_link($root)) {
            throw new InvalidArgumentException(
                "Content view root [{$root}] cannot be a symbolic link.",
            );
        }

        $resolvedRoot = realpath($root);

        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new InvalidArgumentException(
                "Content view root [{$root}] must be an existing directory.",
            );
        }

        $cursor = $destination;

        while (! file_exists($cursor) && dirname($cursor) !== $cursor) {
            $cursor = dirname($cursor);
        }

        if (is_link($cursor)) {
            throw new InvalidArgumentException(
                "Content view destination [{$destination}] traverses a symbolic link.",
            );
        }

        $resolvedCursor = realpath($cursor);
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if ($resolvedCursor === false
            || ($resolvedCursor !== $resolvedRoot
                && ! str_starts_with($resolvedCursor, $rootPrefix))) {
            throw new InvalidArgumentException(
                "Content view destination [{$destination}] escapes its resolved root.",
            );
        }
    }

    private function normalize(string $path): string
    {
        $segments = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
    }
}
