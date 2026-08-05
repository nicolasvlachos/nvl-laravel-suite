<?php

declare(strict_types=1);

namespace Nvl\Content\Services;

use FilesystemIterator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Content\Schema\ContentDefinitionSource;
use Nvl\Content\Support\ContentArrays;
use Nvl\Content\Support\ContentConfiguration;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Deterministically loads inline, PHP, and JSON definition sources.
 */
final class ContentDefinitionLoader
{
    /** @var list<string> */
    private const array DEFINITION_PROPERTIES = [
        'key',
        'name',
        'description',
        'category',
        'version',
        'view',
        'schema',
        'defaults',
        'allowed_scopes',
        'allowedScopes',
        'allowed_regions',
        'allowedRegions',
        'is_active',
        'isActive',
        'sort_order',
        'sortOrder',
    ];

    /**
     * @return list<ContentDefinitionSource>
     */
    public function load(): array
    {
        /** @var array<string, array<string, mixed>> $rawDefinitions */
        $rawDefinitions = [];
        $configured = config('content.definitions', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('content.definitions must be an array.');
        }

        $rawDefinitions = $this->appendDefinitions(
            $rawDefinitions,
            $configured,
            'configuration',
        );

        foreach ($this->definitionFiles() as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                throw new InvalidArgumentException(
                    "Content definition file [{$file}] cannot be read.",
                );
            }

            $loaded = str_ends_with($file, '.json')
                ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR)
                : require $file;

            if (! is_array($loaded)) {
                throw new InvalidArgumentException(
                    "Content definition file [{$file}] must return an array.",
                );
            }

            $rawDefinitions = $this->appendDefinitions($rawDefinitions, $loaded, $file);
        }

        ksort($rawDefinitions);

        return array_map(
            self::definition(...),
            array_values($rawDefinitions),
        );
    }

    /**
     * @param  array<array-key, mixed>  $loaded
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<string, array<string, mixed>>
     */
    private function appendDefinitions(
        array $definitions,
        array $loaded,
        string $source,
    ): array {
        if (isset($loaded['key'], $loaded['name'], $loaded['schema'])) {
            $loaded = [$loaded];
        } elseif (! array_is_list($loaded)) {
            $normalized = [];

            foreach ($loaded as $key => $definition) {
                if (! is_string($key) || ! is_array($definition)) {
                    throw new InvalidArgumentException(
                        "Content definition source [{$source}] has an invalid keyed entry.",
                    );
                }

                $definition = ContentArrays::stringMap(
                    $definition,
                    "content definition {$key}",
                );

                if (array_key_exists('key', $definition)
                    && $definition['key'] !== $key) {
                    throw new InvalidArgumentException(
                        "Content definition [{$key}] conflicts with its nested key.",
                    );
                }

                $normalized[] = [
                    'key' => $key,
                    ...$definition,
                ];
            }

            $loaded = $normalized;
        }

        foreach ($loaded as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                throw new InvalidArgumentException(
                    "Content definition source [{$source}] has an entry without a key.",
                );
            }

            $key = $definition['key'];

            if (isset($definitions[$key])) {
                throw new InvalidArgumentException(
                    "Content definition [{$key}] is declared more than once; latest source [{$source}].",
                );
            }

            $definitions[$key] = ContentArrays::stringMap(
                $definition,
                "content definition {$key}",
            );
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    private function definitionFiles(): array
    {
        $optionalPaths = config('content.definition_paths', []);
        $requiredPaths = config('content.required_definition_paths', []);

        if (! is_array($optionalPaths)) {
            throw new InvalidArgumentException('content.definition_paths must be an array.');
        }

        if (! is_array($requiredPaths)) {
            throw new InvalidArgumentException(
                'content.required_definition_paths must be an array.',
            );
        }

        $roots = $this->allowedRoots();
        /** @var list<string> $files */
        $files = [];

        foreach ([
            ...array_map(
                static fn (mixed $path): array => ['path' => $path, 'required' => false],
                $optionalPaths,
            ),
            ...array_map(
                static fn (mixed $path): array => ['path' => $path, 'required' => true],
                $requiredPaths,
            ),
        ] as $source) {
            $path = $source['path'];

            if (! is_string($path) || trim($path) === '') {
                throw new InvalidArgumentException(
                    'Every content definition path must be a non-empty string.',
                );
            }

            if (! file_exists($path)) {
                if ($source['required']) {
                    throw new InvalidArgumentException(
                        "Required content definition path [{$path}] does not exist.",
                    );
                }

                continue;
            }

            $resolved = realpath($path);

            if ($resolved === false || ! $this->insideRoots($resolved, $roots)) {
                throw new InvalidArgumentException(
                    "Content definition path [{$path}] escapes its allowed roots.",
                );
            }

            if (is_file($resolved)) {
                if ($this->definitionFile($resolved)) {
                    $files[] = $resolved;
                }

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
                    continue;
                }

                $realFile = $file->getRealPath();

                if ($realFile !== false
                    && $this->insideRoots($realFile, $roots)
                    && $this->definitionFile($realFile)) {
                    $files[] = $realFile;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);
        $maximumFiles = ContentConfiguration::positiveInteger(
            'content.definition_limits.maximum_files',
            500,
        );

        if (count($files) > $maximumFiles) {
            throw new InvalidArgumentException(
                "Content definition discovery exceeds the {$maximumFiles} file limit.",
            );
        }

        $maximumBytes = ContentConfiguration::positiveInteger(
            'content.definition_limits.maximum_file_bytes',
            1_048_576,
        );

        foreach ($files as $file) {
            $size = filesize($file);

            if (! is_int($size) || $size > $maximumBytes) {
                throw new InvalidArgumentException(
                    "Content definition file [{$file}] exceeds {$maximumBytes} bytes.",
                );
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function allowedRoots(): array
    {
        $configured = config('content.allowed_definition_roots', [base_path()]);

        if (! is_array($configured) || $configured === []) {
            throw new InvalidArgumentException(
                'content.allowed_definition_roots must contain at least one path.',
            );
        }

        $roots = [];

        foreach ($configured as $root) {
            if (! is_string($root) || ($resolved = realpath($root)) === false) {
                throw new InvalidArgumentException(
                    'Every content definition root must be an existing directory.',
                );
            }

            $roots[] = rtrim($resolved, DIRECTORY_SEPARATOR);
        }

        return array_values(array_unique($roots));
    }

    /**
     * @param  list<string>  $roots
     */
    private function insideRoots(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function definitionFile(string $path): bool
    {
        return str_ends_with($path, '.content.php') || str_ends_with($path, '.content.json');
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private static function camelize(array $definition): array
    {
        $mapped = [];
        $sourceKeys = [];

        foreach ($definition as $key => $value) {
            $normalized = Str::camel($key);

            if (array_key_exists($normalized, $mapped)) {
                throw new InvalidArgumentException(
                    "Content definition properties [{$sourceKeys[$normalized]}] and [{$key}] conflict.",
                );
            }

            $mapped[$normalized] = $value;
            $sourceKeys[$normalized] = $key;
        }

        return [
            'description' => null,
            'category' => 'content',
            'version' => 1,
            'view' => null,
            ...$mapped,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function definition(array $source): ContentDefinitionSource
    {
        $unknown = array_diff(array_keys($source), self::DEFINITION_PROPERTIES);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Content definition has unknown properties: '.implode(', ', $unknown).'.',
            );
        }

        $definition = self::camelize($source);
        $key = $definition['key'] ?? null;
        $name = $definition['name'] ?? null;
        $description = $definition['description'];
        $category = $definition['category'];
        $version = $definition['version'];
        $view = $definition['view'];
        $schema = $definition['schema'] ?? null;
        $defaults = $definition['defaults'] ?? [];
        $allowedScopes = $definition['allowedScopes'] ?? ['global'];
        $allowedRegions = $definition['allowedRegions'] ?? ['main'];
        $isActive = $definition['isActive'] ?? true;
        $sortOrder = $definition['sortOrder'] ?? 0;
        $definitionKey = is_string($key) ? $key : 'unknown';
        $normalizedScopes = self::stringList(
            $allowedScopes,
            "Content definition [{$definitionKey}] allowedScopes",
        );
        $normalizedRegions = self::stringList(
            $allowedRegions,
            "Content definition [{$definitionKey}] allowedRegions",
        );

        if (! is_string($key)
            || ! is_string($name)
            || ! is_string($category)
            || ! is_int($version)
            || ! is_array($schema)
            || ! is_array($defaults)
            || ! is_bool($isActive)
            || ! is_int($sortOrder)
            || $description !== null && ! is_string($description)
            || $view !== null && ! is_string($view)) {
            throw new InvalidArgumentException(
                "Content definition [{$definitionKey}] contains invalid property types.",
            );
        }

        return new ContentDefinitionSource(
            key: $key,
            name: $name,
            description: $description,
            category: $category,
            version: $version,
            view: $view,
            schema: $schema,
            defaults: ContentArrays::stringMap(
                $defaults,
                "content definition {$key} defaults",
            ),
            allowedScopes: $normalizedScopes,
            allowedRegions: $normalizedRegions,
            isActive: $isActive,
            sortOrder: $sortOrder,
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$label} must be a string list.");
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("{$label} must be a string list.");
            }

            $normalized[] = $item;
        }

        return $normalized;
    }
}
