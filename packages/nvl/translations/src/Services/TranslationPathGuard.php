<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\TranslationsException;

/**
 * Validates configured roots and constructs safe translation file paths.
 */
final class TranslationPathGuard
{
    /**
     * Normalize a configured absolute directory path.
     */
    public function root(string $path): string
    {
        $normalized = $this->normalizeSeparators(trim($path));

        if ($normalized === '' || str_contains($normalized, "\0") || str_contains($normalized, '://')) {
            throw new TranslationsException('Translation paths must be local absolute directories.');
        }

        $isUnixAbsolute = str_starts_with($normalized, '/');
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:\//', $normalized) === 1;

        if (! $isUnixAbsolute && ! $isWindowsAbsolute) {
            throw new TranslationsException("Translation path [{$path}] must be absolute.");
        }

        $segments = explode('/', $normalized);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new TranslationsException("Translation path [{$path}] contains ambiguous traversal segments.");
        }

        $normalized = $this->trimTrailingSeparators($normalized);
        $this->assertNoSymlinkComponents($normalized);
        $realPath = realpath($normalized);

        return $realPath === false
            ? $normalized
            : $this->trimTrailingSeparators($this->normalizeSeparators($realPath));
    }

    /**
     * Build a path below a trusted root from validated relative segments.
     */
    public function child(string $root, string ...$segments): string
    {
        $path = $this->root($root);

        foreach ($segments as $segment) {
            $normalized = trim($this->normalizeSeparators($segment), '/');

            if (
                $normalized === ''
                || str_contains($normalized, "\0")
                || in_array('..', explode('/', $normalized), true)
                || in_array('.', explode('/', $normalized), true)
            ) {
                throw new TranslationsException("Unsafe translation path segment [{$segment}].");
            }

            $path = rtrim($path, '/').'/'.$normalized;
        }

        $this->assertNoSymlinkComponents($path);
        $canonicalRoot = $this->root($root);

        if (! file_exists($canonicalRoot)) {
            return $path;
        }

        $existingParent = $this->nearestExistingParent($path);
        $realParent = realpath($existingParent);

        if ($realParent !== false) {
            $normalizedParent = rtrim($this->normalizeSeparators($realParent), '/');

            if (! $this->pathIsWithin($normalizedParent, $canonicalRoot)) {
                throw new TranslationsException(
                    "Translation path [{$path}] escapes its configured root.",
                );
            }
        }

        return $path;
    }

    /**
     * Validate a locale used as a file or directory name.
     */
    public function locale(string $locale): string
    {
        $normalized = trim($locale);

        if (
            $normalized === ''
            || mb_strlen($normalized) > 35
            || preg_match('/^[A-Za-z0-9]+(?:[_.@-][A-Za-z0-9]+)*$/', $normalized) !== 1
        ) {
            throw new InvalidTranslationInputException("Invalid translation locale [{$locale}].");
        }

        return $normalized;
    }

    /**
     * Validate a PHP translation group path.
     */
    public function group(string $group): string
    {
        $normalized = trim($this->normalizeSeparators($group), '/');

        if (
            $normalized === ''
            || mb_strlen($normalized) > 255
            || in_array('..', explode('/', $normalized), true)
            || preg_match('/^[A-Za-z0-9_.@-]+(?:\/[A-Za-z0-9_.@-]+)*$/', $normalized) !== 1
        ) {
            throw new InvalidTranslationInputException("Invalid PHP translation group [{$group}].");
        }

        return $normalized;
    }

    /**
     * Assert that one absolute path remains within a trusted root.
     */
    public function assertWithinRoot(string $root, string $path): void
    {
        $canonicalRoot = $this->root($root);
        $canonicalPath = $this->root($path);

        if (! $this->pathIsWithin($canonicalPath, $canonicalRoot)) {
            throw new TranslationsException(
                "Translation path [{$path}] escapes its configured root.",
            );
        }
    }

    /**
     * Return a validated path relative to its trusted root.
     */
    public function relativeToRoot(string $root, string $path): string
    {
        $canonicalRoot = $this->root($root);
        $canonicalPath = $this->root($path);

        if (! $this->pathIsWithin($canonicalPath, $canonicalRoot)
            || $canonicalPath === $canonicalRoot) {
            throw new TranslationsException(
                "Translation file [{$path}] must be below its configured root.",
            );
        }

        return ltrim(substr($canonicalPath, strlen(rtrim($canonicalRoot, '/'))), '/');
    }

    private function normalizeSeparators(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Reject existing symlink components so a later read/write cannot escape.
     */
    private function assertNoSymlinkComponents(string $path): void
    {
        $normalized = $this->normalizeSeparators($path);
        $segments = array_values(array_filter(
            explode('/', $normalized),
            static fn (string $segment): bool => $segment !== '',
        ));
        $prefix = str_starts_with($normalized, '/') ? '/' : '';

        if (isset($segments[0]) && preg_match('/^[A-Za-z]:$/', $segments[0]) === 1) {
            $prefix = array_shift($segments).'/';
        }

        foreach ($segments as $segment) {
            $prefix = rtrim($prefix, '/').'/'.$segment;

            if (is_link($prefix)) {
                throw new TranslationsException(
                    "Translation path [{$path}] contains a symbolic link.",
                );
            }
        }
    }

    private function nearestExistingParent(string $path): string
    {
        $candidate = $path;

        while (! file_exists($candidate) && dirname($candidate) !== $candidate) {
            $candidate = dirname($candidate);
        }

        return $candidate;
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $normalizedPath = $this->trimTrailingSeparators($this->normalizeSeparators($path));
        $normalizedRoot = $this->trimTrailingSeparators($this->normalizeSeparators($root));

        if ($normalizedPath === $normalizedRoot) {
            return true;
        }

        if ($normalizedRoot === '/') {
            return str_starts_with($normalizedPath, '/');
        }

        return str_starts_with($normalizedPath.'/', rtrim($normalizedRoot, '/').'/');
    }

    private function trimTrailingSeparators(string $path): string
    {
        if ($path === '/' || preg_match('/^[A-Za-z]:\\/$/', $path) === 1) {
            return $path;
        }

        return rtrim($path, '/');
    }
}
