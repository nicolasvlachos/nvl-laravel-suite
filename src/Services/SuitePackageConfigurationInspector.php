<?php

declare(strict_types=1);

namespace Nvl\Suite\Services;

use Illuminate\Filesystem\Filesystem;
use Nvl\Suite\Support\SuiteConfigurationFinding;
use Nvl\Suite\Support\SuiteConfigurationNode;
use Nvl\Suite\Support\SuiteModuleCatalog;
use ReflectionClass;
use RuntimeException;

/**
 * Compares published package configuration structure without evaluating it.
 *
 * @phpstan-import-type ConfigurationDefinition from SuiteModuleCatalog
 *
 * @phpstan-type ConfigurationFinding array{
 *     code: string,
 *     severity: 'error'|'warning',
 *     module: string,
 *     path: string,
 *     symbol: string,
 *     message: string,
 *     remediation: string
 * }
 */
final readonly class SuitePackageConfigurationInspector
{
    private const int EXPANDED_MINIMUM_PATHS = 20;

    private const float EXPANDED_MINIMUM_RATIO = 0.60;

    public function __construct(
        private Filesystem $filesystem,
        private SuiteModuleCatalog $catalog,
        private string $suiteRoot,
        private string $configurationPath,
    ) {}

    /**
     * Inspect selected modules, or every config-bearing module when omitted.
     *
     * @param  list<string>  $modules
     * @return list<ConfigurationFinding>
     */
    public function inspect(array $modules = []): array
    {
        $definitions = $this->catalog->modules();
        $selected = $modules === [] ? array_keys($definitions) : $modules;
        $findings = [];

        foreach ($selected as $module) {
            if (! isset($definitions[$module])) {
                throw new RuntimeException("Unknown suite module [{$module}].");
            }

            $configuration = $definitions[$module]['configuration'];

            if ($configuration === null) {
                continue;
            }

            array_push(
                $findings,
                ...$this->inspectMergeStrategy(
                    module: $module,
                    provider: $definitions[$module]['provider'],
                    configuration: $configuration,
                ),
            );

            $publishedPath = $this->configurationPath.'/'.$configuration['published'];

            if (! $this->filesystem->isFile($publishedPath)) {
                continue;
            }

            array_push(
                $findings,
                ...$this->inspectPublishedSource($module, $configuration, $publishedPath),
            );
        }

        usort($findings, static function (SuiteConfigurationFinding $left, SuiteConfigurationFinding $right): int {
            $severity = ['error' => 0, 'warning' => 1];

            return [$left->module, $severity[$left->severity], $left->path, $left->code]
                <=> [$right->module, $severity[$right->severity], $right->path, $right->code];
        });

        return array_map(
            static fn (SuiteConfigurationFinding $finding): array => $finding->toArray(),
            $findings,
        );
    }

    /**
     * @param  class-string  $provider
     * @param  ConfigurationDefinition  $configuration
     * @return list<SuiteConfigurationFinding>
     */
    private function inspectMergeStrategy(
        string $module,
        string $provider,
        array $configuration,
    ): array {
        $providerPath = (new ReflectionClass($provider))->getFileName();

        if (! is_string($providerPath) || ! $this->filesystem->isFile($providerPath)) {
            return [$this->finding(
                code: 'configuration.source_unavailable',
                severity: 'warning',
                module: $module,
                path: $configuration['key'],
                message: 'The package provider source is unavailable for merge-strategy inspection.',
                remediation: 'Verify the installed package archive contains its provider source.',
            )];
        }

        $source = $this->filesystem->get($providerPath);
        $usesSharedMerger = str_contains($source, 'use MergesPackageConfiguration;')
            && str_contains($source, 'mergePackageConfiguration(');

        if ($usesSharedMerger) {
            return [];
        }

        return [$this->finding(
            code: 'configuration.merge_strategy_mismatch',
            severity: 'error',
            module: $module,
            path: $configuration['key'],
            message: 'The package provider does not implement the catalog-declared deep-map and atomic-list merge strategy.',
            remediation: 'Adopt the shared MergesPackageConfiguration provider trait.',
        )];
    }

    /**
     * @param  ConfigurationDefinition  $configuration
     * @return list<SuiteConfigurationFinding>
     */
    private function inspectPublishedSource(
        string $module,
        array $configuration,
        string $publishedPath,
    ): array {
        $defaultPath = $this->suiteRoot.'/'.$configuration['default'];

        if (! $this->filesystem->isFile($defaultPath)) {
            return [$this->finding(
                code: 'configuration.source_unavailable',
                severity: 'warning',
                module: $module,
                path: $configuration['key'],
                message: 'The package default configuration source is unavailable.',
                remediation: 'Verify the installed package archive contains its default configuration file.',
            )];
        }

        $default = $this->parseSource($this->filesystem->get($defaultPath));
        $published = $this->parseSource($this->filesystem->get($publishedPath));

        if ($default === null || $published === null) {
            return [$this->finding(
                code: 'configuration.source_unavailable',
                severity: 'warning',
                module: $module,
                path: $configuration['key'],
                message: 'A literal returned configuration array could not be identified statically.',
                remediation: 'Keep the published configuration return value as a literal PHP array overlay.',
            )];
        }

        $openMaps = array_values(array_unique([
            ...$configuration['open_maps'],
            ...$this->unavailablePaths($default, $configuration['open_maps']),
        ]));
        $defaultPaths = $this->flatten($default, $openMaps);
        $publishedPaths = $this->flatten($published, $openMaps);
        $findings = $this->unavailableFindings(
            module: $module,
            key: $configuration['key'],
            node: $published,
            openMaps: $openMaps,
        );

        foreach ($configuration['deprecated'] as $path => $replacement) {
            if (! isset($publishedPaths[$path])) {
                continue;
            }

            $findings[] = $this->finding(
                code: 'configuration.deprecated_key',
                severity: 'error',
                module: $module,
                path: $this->fullPath($configuration['key'], $path),
                message: 'The published configuration contains a deprecated key path.',
                remediation: $replacement,
            );
        }

        foreach ($publishedPaths as $path => $kind) {
            if ($this->isDeprecatedPath($path, $configuration['deprecated'])) {
                continue;
            }

            if (! isset($defaultPaths[$path])) {
                $findings[] = $this->finding(
                    code: 'configuration.unknown_key',
                    severity: 'error',
                    module: $module,
                    path: $this->fullPath($configuration['key'], $path),
                    message: 'The published configuration contains a key path absent from the current package default.',
                    remediation: 'Remove the key or replace it with a current documented configuration path.',
                );

                continue;
            }

            if (! $this->kindsAreCompatible($defaultPaths[$path], $kind)) {
                $findings[] = $this->finding(
                    code: 'configuration.unknown_key',
                    severity: 'error',
                    module: $module,
                    path: $this->fullPath($configuration['key'], $path),
                    message: 'The published configuration container kind differs from the current package default.',
                    remediation: 'Use the current documented scalar, map, or list structure for this path.',
                );
            }
        }

        $matchingPaths = array_filter(
            $publishedPaths,
            fn (string $kind, string $path): bool => isset($defaultPaths[$path])
                && $this->kindsAreCompatible($defaultPaths[$path], $kind),
            ARRAY_FILTER_USE_BOTH,
        );
        $defaultCount = count($defaultPaths);
        $matchingCount = count($matchingPaths);
        $expanded = $matchingCount >= self::EXPANDED_MINIMUM_PATHS
            && $defaultCount > 0
            && ($matchingCount / $defaultCount) >= self::EXPANDED_MINIMUM_RATIO;

        if ($expanded) {
            $findings[] = $this->finding(
                code: 'configuration.expanded_overlay',
                severity: 'warning',
                module: $module,
                path: $configuration['key'],
                message: sprintf(
                    'The published file repeats %d of %d closed package configuration paths.',
                    $matchingCount,
                    $defaultCount,
                ),
                remediation: 'Keep only intentional host overrides and rely on package defaults for the remaining paths.',
            );

            foreach ($this->missingBranchRoots($defaultPaths, $publishedPaths) as $path) {
                $findings[] = $this->finding(
                    code: 'configuration.missing_current_branch',
                    severity: 'warning',
                    module: $module,
                    path: $this->fullPath($configuration['key'], $path),
                    message: 'This current package configuration branch is absent from the expanded published snapshot.',
                    remediation: 'Review the current default and either add an intentional override or reduce the file to a minimal overlay.',
                );
            }
        }

        return $findings;
    }

    private function parseSource(string $source): ?SuiteConfigurationNode
    {
        $tokens = token_get_all($source);
        $returnIndex = null;

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_RETURN) {
                $returnIndex = $index;
                break;
            }
        }

        if ($returnIndex === null) {
            return null;
        }

        for ($index = $returnIndex + 1, $count = count($tokens); $index < $count; $index++) {
            if ($this->isTrivia($tokens[$index])) {
                continue;
            }

            if ($tokens[$index] === '[' || $this->tokenId($tokens[$index]) === T_ARRAY) {
                return $this->parseArray(array_slice($tokens, $index));
            }

            return null;
        }

        return null;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private function parseArray(array $tokens): SuiteConfigurationNode
    {
        if ($this->tokenId($tokens[0] ?? '') === T_ARRAY) {
            foreach ($tokens as $index => $token) {
                if ($token === '(') {
                    $tokens = array_slice($tokens, $index);
                    break;
                }
            }
        }

        $segments = $this->arraySegments($tokens);
        $children = [];
        $hasKeyedEntries = false;
        $hasListEntries = false;
        $unavailable = false;

        foreach ($segments as $segment) {
            $segment = $this->trimTrivia($segment);

            if ($segment === []) {
                continue;
            }

            if ($this->tokenId($segment[0]) === T_ELLIPSIS) {
                $unavailable = true;
                $hasListEntries = true;

                continue;
            }

            $arrow = $this->topLevelArrow($segment);

            if ($arrow === null) {
                $hasListEntries = true;

                continue;
            }

            $hasKeyedEntries = true;
            $key = $this->literalKey(array_slice($segment, 0, $arrow));

            if ($key === null) {
                $unavailable = true;

                continue;
            }

            $children[$key] = $this->parseValue(array_slice($segment, $arrow + 1));
        }

        $kind = 'array';

        if ($hasKeyedEntries && ! $hasListEntries) {
            $kind = 'map';
        } elseif ($hasListEntries && ! $hasKeyedEntries) {
            $kind = 'list';
        }

        return new SuiteConfigurationNode(
            kind: $kind,
            children: $children,
            unavailable: $unavailable || ($hasKeyedEntries && $hasListEntries),
        );
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @return list<list<array{int, string, int}|string>>
     */
    private function arraySegments(array $tokens): array
    {
        $segments = [];
        $segment = [];
        $depth = 0;
        $closing = ($tokens[0] ?? null) === '(' ? ')' : ']';

        foreach (array_slice($tokens, 1) as $token) {
            if ($token === $closing && $depth === 0) {
                $segments[] = $segment;

                break;
            }

            if (in_array($token, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($token, [']', ')', '}'], true)) {
                $depth--;
            }

            if ($token === ',' && $depth === 0) {
                $segments[] = $segment;
                $segment = [];

                continue;
            }

            $segment[] = $token;
        }

        return $segments;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private function parseValue(array $tokens): SuiteConfigurationNode
    {
        $tokens = $this->trimTrivia($tokens);

        if (($tokens[0] ?? null) === '[' || $this->tokenId($tokens[0] ?? '') === T_ARRAY) {
            return $this->parseArray($tokens);
        }

        return new SuiteConfigurationNode(kind: 'scalar');
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private function topLevelArrow(array $tokens): ?int
    {
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if (in_array($token, ['[', '(', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($token, [']', ')', '}'], true)) {
                $depth--;

                continue;
            }

            if ($depth === 0 && $this->tokenId($token) === T_DOUBLE_ARROW) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private function literalKey(array $tokens): ?string
    {
        $tokens = $this->trimTrivia($tokens);

        if (count($tokens) !== 1 || ! is_array($tokens[0])) {
            return null;
        }

        if ($tokens[0][0] === T_LNUMBER) {
            return $tokens[0][1];
        }

        if ($tokens[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $literal = $tokens[0][1];
        $quote = $literal[0] ?? '';
        $value = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $value)
            : stripcslashes($value);
    }

    /**
     * @param  list<string>  $openMaps
     * @return array<string, 'array'|'list'|'map'|'scalar'>
     */
    private function flatten(SuiteConfigurationNode $node, array $openMaps, string $prefix = ''): array
    {
        $paths = [];

        foreach ($node->children as $key => $child) {
            $path = $prefix === '' ? $key : $prefix.'.'.$key;
            $paths[$path] = $child->kind;

            if (in_array($path, $openMaps, true)) {
                continue;
            }

            if ($child->kind === 'map' || $child->kind === 'array') {
                $paths += $this->flatten($child, $openMaps, $path);
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @param  list<string>  $openMaps
     * @return list<SuiteConfigurationFinding>
     */
    private function unavailableFindings(
        string $module,
        string $key,
        SuiteConfigurationNode $node,
        array $openMaps,
        string $prefix = '',
    ): array {
        if (in_array($prefix, $openMaps, true)) {
            return [];
        }

        $findings = [];

        if ($node->unavailable) {
            $findings[] = $this->finding(
                code: 'configuration.source_unavailable',
                severity: 'warning',
                module: $module,
                path: $this->fullPath($key, $prefix),
                message: 'A computed key or spread prevents complete static inspection of this branch.',
                remediation: 'Use literal keys outside catalog-declared extension maps.',
            );
        }

        foreach ($node->children as $childKey => $child) {
            $path = $prefix === '' ? $childKey : $prefix.'.'.$childKey;

            if ($child->kind === 'map' || $child->kind === 'array') {
                array_push(
                    $findings,
                    ...$this->unavailableFindings($module, $key, $child, $openMaps, $path),
                );
            }
        }

        return $findings;
    }

    /**
     * Return branches whose default keys cannot be resolved without evaluation.
     *
     * @param  list<string>  $openMaps
     * @return list<string>
     */
    private function unavailablePaths(
        SuiteConfigurationNode $node,
        array $openMaps,
        string $prefix = '',
    ): array {
        if (in_array($prefix, $openMaps, true)) {
            return [];
        }

        $paths = $node->unavailable && $prefix !== '' ? [$prefix] : [];

        foreach ($node->children as $childKey => $child) {
            $path = $prefix === '' ? $childKey : $prefix.'.'.$childKey;

            if ($child->kind === 'map' || $child->kind === 'array') {
                array_push($paths, ...$this->unavailablePaths($child, $openMaps, $path));
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, 'array'|'list'|'map'|'scalar'>  $defaultPaths
     * @param  array<string, 'array'|'list'|'map'|'scalar'>  $publishedPaths
     * @return list<string>
     */
    private function missingBranchRoots(array $defaultPaths, array $publishedPaths): array
    {
        $missing = [];

        foreach (array_keys($defaultPaths) as $path) {
            if (isset($publishedPaths[$path])) {
                continue;
            }

            $hasMissingAncestor = false;

            foreach ($missing as $ancestor) {
                if (str_starts_with($path, $ancestor.'.')) {
                    $hasMissingAncestor = true;
                    break;
                }
            }

            if (! $hasMissingAncestor) {
                $missing[] = $path;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, string>  $deprecated
     */
    private function isDeprecatedPath(string $path, array $deprecated): bool
    {
        foreach (array_keys($deprecated) as $deprecatedPath) {
            if ($path === $deprecatedPath || str_starts_with($path, $deprecatedPath.'.')) {
                return true;
            }
        }

        return false;
    }

    private function kindsAreCompatible(string $default, string $published): bool
    {
        return $default === $published || $default === 'array' || $published === 'array';
    }

    private function fullPath(string $key, string $path): string
    {
        return $path === '' ? $key : $key.'.'.$path;
    }

    /**
     * @param  array{int, string, int}|string  $token
     */
    private function isTrivia(array|string $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param  array{int, string, int}|string  $token
     */
    private function tokenId(array|string $token): ?int
    {
        return is_array($token) ? $token[0] : null;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @return list<array{int, string, int}|string>
     */
    private function trimTrivia(array $tokens): array
    {
        while ($tokens !== [] && $this->isTrivia($tokens[0])) {
            array_shift($tokens);
        }

        while ($tokens !== [] && $this->isTrivia($tokens[array_key_last($tokens)])) {
            array_pop($tokens);
        }

        return $tokens;
    }

    /**
     * @param  'error'|'warning'  $severity
     */
    private function finding(
        string $code,
        string $severity,
        string $module,
        string $path,
        string $message,
        string $remediation,
    ): SuiteConfigurationFinding {
        return new SuiteConfigurationFinding(
            code: $code,
            severity: $severity,
            module: $module,
            path: $path,
            message: $message,
            remediation: $remediation,
        );
    }
}
