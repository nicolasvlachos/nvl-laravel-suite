<?php

declare(strict_types=1);

namespace Nvl\Suite\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Nvl\Suite\Support\SuiteModuleCatalog;
use RuntimeException;
use Symfony\Component\Filesystem\Path;

/**
 * Builds and safely writes deterministic suite adoption configurations.
 */
final readonly class SuiteConfigurationRenderer
{
    public function __construct(
        private Application $application,
        private Filesystem $filesystem,
        private SuiteModuleCatalog $catalog,
    ) {}

    /**
     * Resolve a profile and explicit additions through the dependency graph.
     *
     * @param  list<string>  $additions
     * @return array<string, bool>
     */
    public function modules(?string $profile, array $additions): array
    {
        if ($profile === null && $additions === []) {
            throw new RuntimeException('Select a suite profile or at least one module with --add.');
        }

        $selected = [];

        if ($profile !== null) {
            foreach ($this->catalog->profileModules($profile) as $module) {
                $selected[$module] = true;
            }
        }

        foreach ($additions as $module) {
            if ($module === '') {
                throw new RuntimeException('The --add option must contain non-empty module names.');
            }

            $this->select($module, $selected);
        }

        $modules = [];

        foreach (array_keys($this->catalog->modules()) as $module) {
            $modules[$module] = isset($selected[$module]);
        }

        return $modules;
    }

    /**
     * Render a complete, canonical, publishable suite configuration.
     *
     * @param  array<string, bool>  $modules
     */
    public function render(array $modules): string
    {
        $moduleLines = [];

        foreach (array_keys($this->catalog->modules()) as $module) {
            $moduleLines[] = sprintf(
                "        '%s' => %s,",
                $module,
                ($modules[$module] ?? false) ? 'true' : 'false',
            );
        }

        return sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Suite Modules
    |--------------------------------------------------------------------------
    |
    | Every module decision is explicit. Required dependencies are enabled by
    | the generator so this file remains safe to review, publish, and upgrade.
    |
    */
    'modules' => [
%s
    ],

    'adoption' => [
        'require_explicit_module_decisions' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer Audit
    |--------------------------------------------------------------------------
    |
    | Paths extend Composer source discovery. Suppressions are exact, reviewed
    | exceptions; broad patterns are intentionally unsupported.
    |
    */
    'consumer_audit' => [
        'paths' => ['app', 'config', 'database/migrations', 'routes'],
        'authentication_middleware' => ['auth'],
        'suppressions' => [],
    ],
];

PHP,
            implode("\n", $moduleLines),
        );
    }

    /**
     * Resolve a PHP destination contained by the application root.
     */
    public function resolvePath(?string $path, bool $mustExist = false): string
    {
        $applicationRoot = Path::canonicalize($this->application->basePath());
        $candidate = Path::makeAbsolute($path ?? 'config/nvl-suite.php', $applicationRoot);

        if (strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) !== 'php') {
            throw new RuntimeException('The suite configuration path must use the .php extension.');
        }

        if (! Path::isBasePath($applicationRoot, $candidate)) {
            throw new RuntimeException('The suite configuration path must be inside the application root.');
        }

        $realApplicationRoot = realpath($applicationRoot);
        $realParent = realpath(dirname($candidate));

        if ($realApplicationRoot === false || $realParent === false || ! is_dir($realParent)) {
            throw new RuntimeException('The suite configuration parent directory must exist.');
        }

        if (! Path::isBasePath($realApplicationRoot, $realParent)) {
            throw new RuntimeException('The suite configuration path must not escape through a symbolic link.');
        }

        if ($this->filesystem->exists($candidate)) {
            $realCandidate = realpath($candidate);

            if (! $this->filesystem->isFile($candidate)
                || ! $this->filesystem->isReadable($candidate)
                || $realCandidate === false
                || ! Path::isBasePath($realApplicationRoot, $realCandidate)) {
                throw new RuntimeException('The existing suite configuration must be a readable in-application file.');
            }
        } elseif ($mustExist) {
            throw new RuntimeException('The suite configuration file does not exist.');
        }

        return $candidate;
    }

    /**
     * Return an application-relative path suitable for diagnostics.
     */
    public function relativePath(string $path): string
    {
        return Path::makeRelative($path, $this->application->basePath());
    }

    /**
     * Atomically replace the selected application configuration.
     */
    public function write(string $path, string $contents): void
    {
        $this->filesystem->replace($path, $contents);
    }

    /**
     * @param  array<string, true>  $selected
     */
    private function select(string $module, array &$selected): void
    {
        $definitions = $this->catalog->modules();

        if (! isset($definitions[$module])) {
            throw new RuntimeException("Unknown suite module [{$module}].");
        }

        foreach ($definitions[$module]['dependencies'] as $dependency) {
            $this->select($dependency, $selected);
        }

        $selected[$module] = true;
    }
}
