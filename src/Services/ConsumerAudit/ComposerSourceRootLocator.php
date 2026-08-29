<?php

declare(strict_types=1);

namespace Nvl\Suite\Services\ConsumerAudit;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;

/**
 * Locates application-owned PHP roots from the consumer's root Composer file.
 */
final readonly class ComposerSourceRootLocator
{
    public function __construct(
        private Filesystem $filesystem,
        private Repository $configuration,
    ) {}

    /**
     * @return list<non-empty-string>
     */
    public function locate(string $basePath): array
    {
        $root = realpath($basePath);

        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException('The consumer application path must be an existing directory.');
        }

        $composerPath = $root.'/composer.json';

        if (! is_file($composerPath)) {
            throw new InvalidArgumentException('The consumer application root must contain composer.json.');
        }

        try {
            $composer = json_decode(
                $this->filesystem->get($composerPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new InvalidArgumentException('The consumer application composer.json is invalid.');
        }

        if (! is_array($composer)) {
            throw new InvalidArgumentException('The consumer application composer.json must contain an object.');
        }

        $paths = ['app', 'Modules', 'database/migrations', 'routes', 'config'];
        $autoload = $composer['autoload'] ?? [];

        if (is_array($autoload)) {
            $paths = [
                ...$paths,
                ...$this->autoloadPaths($autoload['psr-4'] ?? [], excludeSuiteNamespaces: true),
                ...$this->autoloadPaths($autoload['classmap'] ?? []),
            ];
        }

        $configured = $this->configuration->get('nvl-suite.consumer_audit.paths', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('nvl-suite.consumer_audit.paths must be an array of relative paths.');
        }

        foreach ($configured as $path) {
            if (! is_string($path) || trim($path) === '') {
                throw new InvalidArgumentException('Every consumer audit path must be a non-empty relative path.');
            }

            $paths[] = $path;
        }

        $roots = [];

        foreach ($paths as $path) {
            $relative = str_replace('\\', '/', trim($path));

            if ($relative === '' || str_starts_with($relative, '/') || preg_match('/(^|\\/)\.\.(?:\\/|$)/', $relative) === 1) {
                throw new InvalidArgumentException('Consumer audit paths must remain inside the application root.');
            }

            $candidate = realpath($root.'/'.trim($relative, '/'));

            if ($candidate === false || (! is_dir($candidate) && ! is_file($candidate))) {
                continue;
            }

            if ($candidate !== $root && ! str_starts_with($candidate, $root.DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException('Consumer audit paths must remain inside the application root.');
            }

            $roots[$candidate] = true;
        }

        $located = array_keys($roots);
        sort($located);

        return $located;
    }

    /**
     * @return list<string>
     */
    private function autoloadPaths(
        mixed $definition,
        bool $excludeSuiteNamespaces = false,
    ): array {
        if (! is_array($definition)) {
            return [];
        }

        $paths = [];

        foreach ($definition as $key => $value) {
            if ($excludeSuiteNamespaces
                && is_string($key)
                && str_starts_with(ltrim($key, '\\'), 'Nvl\\')) {
                continue;
            }

            $values = is_int($key) ? [$value] : (is_array($value) ? $value : [$value]);

            foreach ($values as $path) {
                if (is_string($path)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }
}
