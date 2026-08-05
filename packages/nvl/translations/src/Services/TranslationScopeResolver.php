<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Support\Facades\File;
use Nvl\Translations\Enums\TranslationScopeType;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Support\TranslationScope;

/**
 * Resolves trusted translation source scopes and named export destinations.
 */
final class TranslationScopeResolver
{
    /**
     * Create a resolver constrained by the translation path guard.
     */
    public function __construct(
        private readonly TranslationPathGuard $paths,
    ) {}

    /**
     * Discover every configured and convention-based scope.
     *
     * @return list<TranslationScope>
     */
    public function discoverScopes(): array
    {
        $scopes = [
            new TranslationScope(
                TranslationScopeType::App,
                'app',
                $this->configuredPath('translations.paths.app', lang_path()),
            ),
        ];

        if ((bool) config('translations.discovery.modules', true)) {
            $scopes = [...$scopes, ...$this->discoverModuleScopes()];
        }

        if ((bool) config('translations.discovery.vendor', true)) {
            $scopes = [...$scopes, ...$this->discoverVendorScopes()];
        }

        $scopes = [...$scopes, ...$this->configuredCustomScopes()];
        $unique = [];

        foreach ($scopes as $scope) {
            if (isset($unique[$scope->token()])
                && $unique[$scope->token()]->path !== $scope->path) {
                throw new TranslationsException(
                    "Translation scope [{$scope->token()}] resolves to multiple directories.",
                );
            }

            $unique[$scope->token()] = $scope;
        }

        ksort($unique);
        $this->ensureScopesDoNotOverlap(array_values($unique));

        return array_values($unique);
    }

    /**
     * Resolve scopes from CLI, HTTP, or programmatic option tokens.
     *
     * @param  list<string>  $scopeTokens
     * @return list<TranslationScope>
     */
    public function resolveScopes(array $scopeTokens): array
    {
        $all = $this->discoverScopes();

        if ($scopeTokens === []) {
            return $all;
        }

        $byToken = [];
        foreach ($all as $scope) {
            $byToken[$scope->token()] = $scope;
        }

        $resolved = [];
        $unknown = [];

        foreach ($scopeTokens as $token) {
            $normalized = trim($token);

            if (isset($byToken[$normalized])) {
                $resolved[$normalized] = $byToken[$normalized];

                continue;
            }

            $unknown[] = $normalized;
        }

        if ($unknown !== []) {
            throw new InvalidTranslationInputException(
                'Unknown translation scope token(s): '.implode(', ', array_unique($unknown)).'.',
            );
        }

        return array_values($resolved);
    }

    /**
     * Resolve a configured output directory for a named export target.
     */
    public function resolveExportPath(TranslationScope $scope, string $target = 'source'): string
    {
        $targetName = trim($target);

        if ($targetName === '' || $targetName === 'source') {
            return $scope->path;
        }

        $this->assertTargetName($targetName);
        $targets = config('translations.export_targets', []);

        if (! is_array($targets) || ! array_key_exists($targetName, $targets)) {
            throw new InvalidTranslationInputException("Unknown translation export target [{$targetName}].");
        }

        $mapping = $targets[$targetName];
        if (! is_array($mapping)) {
            throw new TranslationsException("Translation export target [{$targetName}] must be a scope-to-path map.");
        }

        $path = $mapping[$scope->token()] ?? null;
        if (! is_string($path) || trim($path) === '') {
            throw new InvalidTranslationInputException(
                "Export target [{$targetName}] has no path for scope [{$scope->token()}].",
            );
        }

        return $this->paths->root($path);
    }

    /**
     * Resolve and validate distinct export destinations for selected scopes.
     *
     * @param  list<TranslationScope>  $scopes
     * @return array<string, string>
     */
    public function resolveExportPaths(array $scopes, string $target = 'source'): array
    {
        $paths = [];

        foreach ($scopes as $scope) {
            $paths[$scope->token()] = $this->resolveExportPath($scope, $target);
        }

        $backupDirectory = config('translations.backup.directory');
        if ((bool) config('translations.backup.enabled', true)
            && is_string($backupDirectory)
            && trim($backupDirectory) !== '') {
            $backupPath = $this->paths->root($backupDirectory);

            foreach ($paths as $token => $path) {
                if ($this->pathsOverlap($path, $backupPath)) {
                    throw new TranslationsException(
                        "Export target [{$target}:{$token}] overlaps the backup directory.",
                    );
                }
            }
        }

        if (trim($target) === '' || trim($target) === 'source') {
            return $paths;
        }

        foreach ($paths as $token => $path) {
            foreach ($this->discoverScopes() as $sourceScope) {
                if ($this->pathsOverlap($path, $sourceScope->path)) {
                    throw new TranslationsException(
                        "Export target [{$target}:{$token}] overlaps source scope [{$sourceScope->token()}].",
                    );
                }
            }
        }

        $tokens = array_keys($paths);
        foreach ($tokens as $index => $token) {
            foreach (array_slice($tokens, $index + 1) as $otherToken) {
                if ($this->pathsOverlap($paths[$token], $paths[$otherToken])) {
                    throw new TranslationsException(
                        "Export target [{$target}] maps [{$token}] and [{$otherToken}] to overlapping directories.",
                    );
                }
            }
        }

        return $paths;
    }

    /**
     * Resolve a namespaced translation key to a configured scope.
     */
    public function resolveNamespace(string $namespace): ?TranslationScope
    {
        $normalized = mb_strtolower(trim($namespace));

        if ($normalized === '') {
            return null;
        }

        $namespaceMap = $this->namespaceMap();

        if (isset($namespaceMap[$normalized])) {
            return $this->resolveScopes([$namespaceMap[$normalized]])[0] ?? null;
        }

        $matches = [];

        foreach ($this->discoverScopes() as $scope) {
            if (mb_strtolower($scope->name) === $normalized) {
                $matches[] = $scope;
            }
        }

        if (count($matches) > 1) {
            throw new TranslationsException(
                "Translation namespace [{$namespace}] is ambiguous; configure translations.scan.namespaces.",
            );
        }

        return $matches[0] ?? null;
    }

    /**
     * Validate every configured scanner namespace and referenced scope token.
     */
    public function validateNamespaceConfiguration(): void
    {
        foreach ($this->namespaceMap() as $token) {
            $this->resolveScopes([$token]);
        }
    }

    /**
     * Resolve a module name from a translation namespace.
     */
    public function resolveModuleNameFromNamespace(string $namespace): ?string
    {
        $scope = $this->resolveNamespace($namespace);

        return $scope?->type === TranslationScopeType::Module ? $scope->name : null;
    }

    /**
     * @return list<TranslationScope>
     */
    private function discoverModuleScopes(): array
    {
        $configuredRoots = config('translations.module_roots', []);

        if (! is_array($configuredRoots)) {
            throw new TranslationsException('translations.module_roots must be a list of absolute directories.');
        }

        $scopes = [];

        foreach ($configuredRoots as $configuredRoot) {
            if (! is_string($configuredRoot) || trim($configuredRoot) === '') {
                throw new TranslationsException(
                    'Every translations.module_roots item must be a non-empty absolute path.',
                );
            }

            $modulesRoot = $this->paths->root($configuredRoot);

            if (! File::isDirectory($modulesRoot)) {
                continue;
            }

            foreach (File::directories($modulesRoot) as $moduleDirectory) {
                if (! is_string($moduleDirectory)) {
                    continue;
                }

                $moduleName = basename($moduleDirectory);
                $this->assertScopeName($moduleName);
                $candidates = [
                    $this->paths->child($modulesRoot, $moduleName, 'lang'),
                    $this->paths->child($modulesRoot, $moduleName, 'Resources', 'lang'),
                ];

                foreach ($candidates as $candidate) {
                    if (! File::isDirectory($candidate)) {
                        continue;
                    }

                    $scopes[] = new TranslationScope(TranslationScopeType::Module, $moduleName, $candidate);

                    break;
                }
            }
        }

        return $scopes;
    }

    /**
     * @return list<TranslationScope>
     */
    private function discoverVendorScopes(): array
    {
        $vendorRoot = $this->configuredPath('translations.paths.vendor', lang_path('vendor'));

        if (! File::isDirectory($vendorRoot)) {
            return [];
        }

        $scopes = [];

        foreach (File::directories($vendorRoot) as $packagePath) {
            if (! is_string($packagePath)) {
                continue;
            }

            $packageName = basename($packagePath);
            $this->assertScopeName($packageName);

            $scopes[] = new TranslationScope(
                TranslationScopeType::Vendor,
                $packageName,
                $this->paths->root($packagePath),
            );
        }

        return $scopes;
    }

    /**
     * @return list<TranslationScope>
     */
    private function configuredCustomScopes(): array
    {
        $configured = config('translations.custom_scopes', []);

        if (! is_array($configured)) {
            throw new TranslationsException('translations.custom_scopes must be an associative name-to-path map.');
        }

        $scopes = [];

        foreach ($configured as $name => $path) {
            if (! is_string($name) || trim($name) === '' || ! is_string($path) || trim($path) === '') {
                throw new TranslationsException('Every custom translation scope requires a non-empty string name and path.');
            }

            $this->assertScopeName($name);

            $scopes[] = new TranslationScope(
                TranslationScopeType::Custom,
                $name,
                $this->paths->root($path),
            );
        }

        return $scopes;
    }

    private function configuredPath(string $key, string $fallback): string
    {
        $configured = config($key, $fallback);

        if (! is_string($configured) || trim($configured) === '') {
            throw new TranslationsException("Translation path config [{$key}] must be a non-empty string.");
        }

        return $this->paths->root($configured);
    }

    private function assertScopeName(string $name): void
    {
        if (mb_strlen($name) > 120
            || preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
            throw new TranslationsException("Invalid translation scope name [{$name}].");
        }
    }

    private function assertTargetName(string $name): void
    {
        if (mb_strlen($name) > 120
            || preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
            throw new InvalidTranslationInputException(
                "Invalid translation export target [{$name}].",
            );
        }
    }

    /**
     * Return a case-insensitive namespace map with duplicate aliases rejected.
     *
     * @return array<string, string>
     */
    private function namespaceMap(): array
    {
        $configured = config('translations.scan.namespaces', []);

        if (! is_array($configured)) {
            throw new TranslationsException('translations.scan.namespaces must be a namespace-to-scope map.');
        }

        $normalized = [];

        foreach ($configured as $alias => $token) {
            if (! is_string($alias)
                || trim($alias) === ''
                || ! is_string($token)
                || trim($token) === '') {
                throw new TranslationsException(
                    'Every translations.scan.namespaces item requires a non-empty alias and scope token.',
                );
            }

            $namespace = mb_strtolower(trim($alias));
            $scopeToken = trim($token);

            if (isset($normalized[$namespace])
                && $normalized[$namespace] !== $scopeToken) {
                throw new TranslationsException(
                    "Translation namespace [{$alias}] maps to multiple scope tokens.",
                );
            }

            $normalized[$namespace] = $scopeToken;
        }

        return $normalized;
    }

    private function pathsOverlap(string $leftPath, string $rightPath): bool
    {
        $left = rtrim(str_replace('\\', '/', $leftPath), '/').'/';
        $right = rtrim(str_replace('\\', '/', $rightPath), '/').'/';

        return $left === $right
            || str_starts_with($left, $right)
            || str_starts_with($right, $left);
    }

    /**
     * Prevent one profile from recursively reading or overwriting another.
     *
     * @param  list<TranslationScope>  $scopes
     */
    private function ensureScopesDoNotOverlap(array $scopes): void
    {
        foreach ($scopes as $index => $scope) {
            foreach (array_slice($scopes, $index + 1) as $other) {
                $left = rtrim($scope->path, '/').'/';
                $right = rtrim($other->path, '/').'/';
                $isConventionalVendorNesting = (
                    $scope->type === TranslationScopeType::App
                    && $other->type === TranslationScopeType::Vendor
                    && str_starts_with($right, $left.'vendor/')
                ) || (
                    $other->type === TranslationScopeType::App
                    && $scope->type === TranslationScopeType::Vendor
                    && str_starts_with($left, $right.'vendor/')
                );

                if ($left === $right
                    || str_starts_with($left, $right)
                    || str_starts_with($right, $left)) {
                    if ($isConventionalVendorNesting) {
                        continue;
                    }

                    throw new TranslationsException(
                        "Translation scopes [{$scope->token()}] and [{$other->token()}] overlap.",
                    );
                }
            }
        }
    }
}
