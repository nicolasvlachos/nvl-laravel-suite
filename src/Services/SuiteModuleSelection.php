<?php

declare(strict_types=1);

namespace Nvl\Suite\Services;

use Nvl\Suite\Support\SuiteModuleCatalog;
use RuntimeException;

/**
 * Resolves legacy or profile-first configuration into one canonical module set.
 */
final readonly class SuiteModuleSelection
{
    /**
     * @param  'legacy'|'declarative'  $source
     * @param  list<string>  $include
     * @param  list<string>  $exclude
     * @param  array<string, bool>  $enabled
     * @param  array<string, bool>  $requested
     * @param  array<string, 'enabled'|'disabled'|'implicit'>  $decisions
     */
    private function __construct(
        public string $source,
        public ?string $profile,
        public array $include,
        public array $exclude,
        private array $enabled,
        private array $requested,
        private array $decisions,
    ) {}

    /**
     * Resolve raw or runtime-merged suite configuration.
     *
     * @param  array<mixed>  $configuration
     */
    public static function fromConfiguration(
        array $configuration,
        SuiteModuleCatalog $catalog,
    ): self {
        $legacy = $configuration['modules'] ?? null;

        if ($legacy !== null) {
            if (! is_array($legacy)) {
                throw new RuntimeException('nvl-suite.modules must be null or an array of module boolean flags.');
            }

            return self::legacy($legacy, $catalog);
        }

        return self::declarative($configuration, $catalog);
    }

    /**
     * Return all enabled modules in catalog order.
     *
     * @return list<string>
     */
    public function effectiveModules(): array
    {
        return array_keys(array_filter($this->enabled));
    }

    /**
     * Return one decision for every catalog module.
     *
     * @return array<string, bool>
     */
    public function modules(): array
    {
        return $this->enabled;
    }

    public function enabled(string $module): bool
    {
        return $this->enabled[$module] ?? throw new RuntimeException("Unknown suite module [{$module}].");
    }

    public function requested(string $module): bool
    {
        return $this->requested[$module] ?? throw new RuntimeException("Unknown suite module [{$module}].");
    }

    /**
     * @return 'enabled'|'disabled'|'implicit'
     */
    public function decision(string $module): string
    {
        return $this->decisions[$module] ?? throw new RuntimeException("Unknown suite module [{$module}].");
    }

    /**
     * @param  array<mixed>  $legacy
     */
    private static function legacy(array $legacy, SuiteModuleCatalog $catalog): self
    {
        $definitions = $catalog->modules();
        $configured = [];
        $decisions = [];
        $unknown = [];

        foreach ($legacy as $module => $value) {
            if (! is_string($module) || ! isset($definitions[$module])) {
                $unknown[] = is_string($module) ? $module : 'non-string-module-key';

                continue;
            }

            if (! is_bool($value)) {
                throw new RuntimeException("Suite module [{$module}] must be configured with a boolean flag.");
            }

            $configured[$module] = $value;
        }

        if ($unknown !== []) {
            throw new RuntimeException(sprintf(
                'Unknown suite module configuration: %s.',
                implode(', ', $unknown),
            ));
        }

        $requested = [];

        foreach (array_keys($definitions) as $module) {
            $explicit = array_key_exists($module, $configured);
            $requested[$module] = $explicit ? $configured[$module] : false;
            $decisions[$module] = $explicit
                ? ($configured[$module] ? 'enabled' : 'disabled')
                : 'implicit';
        }

        return new self(
            source: 'legacy',
            profile: null,
            include: [],
            exclude: [],
            enabled: self::dependencyClosure($requested, [], $catalog),
            requested: $requested,
            decisions: $decisions,
        );
    }

    /**
     * @param  array<mixed>  $configuration
     */
    private static function declarative(
        array $configuration,
        SuiteModuleCatalog $catalog,
    ): self {
        $profile = $configuration['profile'] ?? null;

        if ($profile !== null && ! is_string($profile)) {
            throw new RuntimeException('nvl-suite.profile must be null or a profile name.');
        }

        if (is_string($profile) && ! isset($catalog->profiles()[$profile])) {
            throw new RuntimeException("Unknown suite installation profile [{$profile}].");
        }

        $include = self::moduleList($configuration['include'] ?? [], 'include', $catalog);
        $exclude = self::moduleList($configuration['exclude'] ?? [], 'exclude', $catalog);
        $roots = is_string($profile) ? $catalog->profiles()[$profile]['modules'] : [];
        $roots = self::canonicalModules([...$roots, ...$include], $catalog);
        $conflicts = array_values(array_intersect($roots, $exclude));

        if ($conflicts !== []) {
            throw new RuntimeException(sprintf(
                'Selected suite roots cannot also be excluded: %s.',
                implode(', ', $conflicts),
            ));
        }

        $requested = array_fill_keys(array_keys($catalog->modules()), false);

        foreach ($roots as $root) {
            $requested[$root] = true;
        }

        $enabled = self::dependencyClosure($requested, $exclude, $catalog);
        $decisions = array_map(
            static fn (bool $moduleEnabled): string => $moduleEnabled ? 'enabled' : 'disabled',
            $enabled,
        );

        return new self(
            source: 'declarative',
            profile: $profile,
            include: $include,
            exclude: $exclude,
            enabled: $enabled,
            requested: $requested,
            decisions: $decisions,
        );
    }

    /**
     * @return list<string>
     */
    private static function moduleList(
        mixed $modules,
        string $key,
        SuiteModuleCatalog $catalog,
    ): array {
        if (! is_array($modules) || ! array_is_list($modules)) {
            throw new RuntimeException("nvl-suite.{$key} must be a list of module names.");
        }

        foreach ($modules as $module) {
            if (! is_string($module) || $module === '') {
                throw new RuntimeException("nvl-suite.{$key} must contain non-empty module names.");
            }
        }

        return self::canonicalModules($modules, $catalog);
    }

    /**
     * @param  list<string>  $modules
     * @return list<string>
     */
    private static function canonicalModules(array $modules, SuiteModuleCatalog $catalog): array
    {
        $definitions = $catalog->modules();
        $unknown = array_values(array_diff($modules, array_keys($definitions)));

        if ($unknown !== []) {
            throw new RuntimeException(sprintf(
                'Unknown suite module selection: %s.',
                implode(', ', array_values(array_unique($unknown))),
            ));
        }

        $selected = array_fill_keys($modules, true);

        return array_values(array_filter(
            array_keys($definitions),
            static fn (string $module): bool => isset($selected[$module]),
        ));
    }

    /**
     * @param  array<string, bool>  $requested
     * @param  list<string>  $exclude
     * @return array<string, bool>
     */
    private static function dependencyClosure(
        array $requested,
        array $exclude,
        SuiteModuleCatalog $catalog,
    ): array {
        $definitions = $catalog->modules();
        $selected = [];

        foreach ($requested as $root => $enabled) {
            if ($enabled) {
                self::select($root, $root, $exclude, $definitions, $selected);
            }
        }

        $resolved = [];

        foreach (array_keys($definitions) as $module) {
            $resolved[$module] = isset($selected[$module]);
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $exclude
     * @param  array<string, array{dependencies: list<string>}>  $definitions
     * @param  array<string, true>  $selected
     */
    private static function select(
        string $module,
        string $root,
        array $exclude,
        array $definitions,
        array &$selected,
    ): void {
        if (in_array($module, $exclude, true)) {
            throw new RuntimeException(
                "Suite module [{$module}] is a required dependency of selected root [{$root}] and cannot be excluded.",
            );
        }

        foreach ($definitions[$module]['dependencies'] as $dependency) {
            self::select($dependency, $root, $exclude, $definitions, $selected);
        }

        $selected[$module] = true;
    }
}
