<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * Resolves local files and directories beneath existing non-symlink roots.
 */
final readonly class SafeFilesystemPathResolver
{
    public function __construct(private Filesystem $files) {}

    /**
     * Resolve an allowed directory and optionally create it.
     *
     * @param  list<mixed>  $allowedRoots
     */
    public function directory(
        string $path,
        array $allowedRoots,
        bool $create = false,
        bool $writable = false,
        string $description = 'Filesystem path',
    ): string {
        $normalized = $this->absolutePath($path, $description);
        $root = $this->allowedRoot($normalized, $allowedRoots, $description);
        $this->assertResolvedContainment($normalized, $root, $writable, $description);

        if (! $create) {
            return $normalized;
        }

        $this->files->ensureDirectoryExists($normalized, 0750, true);
        $this->assertResolvedContainment($normalized, $root, $writable, $description);
        $resolved = realpath($normalized);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException("{$description} could not be resolved as a directory.");
        }

        return $resolved;
    }

    /**
     * Resolve an allowed regular-file target and optionally create its parent.
     *
     * @param  list<mixed>  $allowedRoots
     */
    public function file(
        string $path,
        array $allowedRoots,
        ?string $requiredExtension = null,
        bool $createParent = false,
        string $description = 'Filesystem file',
    ): string {
        $normalized = $this->absolutePath($path, $description);

        if ($requiredExtension !== null) {
            $extension = '.'.ltrim(mb_strtolower($requiredExtension), '.');

            if (! str_ends_with(mb_strtolower($normalized), $extension)) {
                $normalized .= $extension;
            }
        }

        $root = $this->allowedRoot($normalized, $allowedRoots, $description);
        $parent = dirname($normalized);
        $this->assertResolvedContainment($parent, $root, true, $description);

        if ($createParent) {
            $this->directory(
                $parent,
                [$root],
                create: true,
                writable: true,
                description: $description,
            );
        }

        $this->assertResolvedContainment($parent, $root, true, $description);

        if (is_link($normalized)
            || (file_exists($normalized) && ! is_file($normalized))) {
            throw new InvalidArgumentException(
                "{$description} must target a regular non-symlink file.",
            );
        }

        return $normalized;
    }

    private function absolutePath(string $path, string $description): string
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_contains($path, "\0")) {
            throw new InvalidArgumentException("{$description} must be an absolute safe path.");
        }

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

    /**
     * @param  list<mixed>  $allowedRoots
     */
    private function allowedRoot(
        string $path,
        array $allowedRoots,
        string $description,
    ): string {
        if ($allowedRoots === []) {
            throw new InvalidArgumentException("{$description} requires at least one allowed root.");
        }

        $matchingRoot = null;

        foreach ($allowedRoots as $root) {
            if (! is_string($root)
                || ! str_starts_with($root, DIRECTORY_SEPARATOR)
                || str_contains($root, "\0")) {
                throw new InvalidArgumentException(
                    "{$description} allowed roots must be absolute path strings.",
                );
            }

            $normalizedRoot = $this->absolutePath($root, "{$description} root");
            $prefix = rtrim($normalizedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

            if (($path === $normalizedRoot || str_starts_with($path, $prefix))
                && ($matchingRoot === null
                    || strlen($normalizedRoot) > strlen($matchingRoot))) {
                $matchingRoot = $normalizedRoot;
            }
        }

        if ($matchingRoot === null) {
            throw new InvalidArgumentException("{$description} is outside its allowed roots.");
        }

        return $matchingRoot;
    }

    private function assertResolvedContainment(
        string $path,
        string $root,
        bool $writable,
        string $description,
    ): void {
        if (is_link($root)) {
            throw new InvalidArgumentException("{$description} root cannot be a symbolic link.");
        }

        $resolvedRoot = realpath($root);

        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new InvalidArgumentException(
                "{$description} root must be an existing directory.",
            );
        }

        $this->assertNoSymlinkComponents($path, $root, $description);
        $cursor = $path;

        while (! file_exists($cursor)
            && ! is_link($cursor)
            && dirname($cursor) !== $cursor) {
            $cursor = dirname($cursor);
        }

        if (is_link($cursor)) {
            throw new InvalidArgumentException("{$description} traverses a symbolic link.");
        }

        $resolvedCursor = realpath($cursor);
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if ($resolvedCursor === false
            || ($resolvedCursor !== $resolvedRoot
                && ! str_starts_with($resolvedCursor, $rootPrefix))) {
            throw new InvalidArgumentException("{$description} escapes its resolved root.");
        }

        if ($writable && ! is_writable($resolvedCursor)) {
            throw new InvalidArgumentException(
                "{$description} has no safe writable existing ancestor.",
            );
        }
    }

    private function assertNoSymlinkComponents(
        string $path,
        string $root,
        string $description,
    ): void {
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        $cursor = $root;

        if ($relative === '') {
            return;
        }

        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($cursor)) {
                throw new InvalidArgumentException(
                    "{$description} traverses symbolic link [{$cursor}].",
                );
            }

            if (! file_exists($cursor)) {
                break;
            }
        }
    }
}
