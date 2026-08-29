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
     * Resolve profile roots and explicit overlays through the shared selection model.
     *
     * @param  list<string>  $additions
     * @param  list<string>  $removals
     */
    public function selection(
        ?string $profile,
        array $additions,
        array $removals = [],
    ): SuiteModuleSelection {
        if ($profile === null && $additions === []) {
            throw new RuntimeException('Select a suite profile or at least one module with --add.');
        }

        return SuiteModuleSelection::fromConfiguration([
            'modules' => null,
            'profile' => $profile,
            'include' => $additions,
            'exclude' => $removals,
        ], $this->catalog);
    }

    /**
     * Resolve a profile and explicit overlays into every module decision.
     *
     * @param  list<string>  $additions
     * @param  list<string>  $removals
     * @return array<string, bool>
     */
    public function modules(
        ?string $profile,
        array $additions,
        array $removals = [],
    ): array {
        return $this->selection($profile, $additions, $removals)->modules();
    }

    /**
     * Render the smallest runtime selection overlay.
     */
    public function renderMinimal(SuiteModuleSelection $selection): string
    {
        return sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'profile' => %s,
    'include' => %s,
    'exclude' => %s,
];

PHP,
            $selection->profile === null ? 'null' : "'{$selection->profile}'",
            $this->renderList($selection->include),
            $this->renderList($selection->exclude),
        );
    }

    /**
     * Render a complete, canonical, publishable legacy suite configuration.
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
     * Render a reviewable unified diff when a destination already exists.
     */
    public function diff(string $path, string $contents): ?string
    {
        if (! $this->filesystem->exists($path)) {
            return null;
        }

        $current = $this->filesystem->get($path);

        if ($current === $contents) {
            return '';
        }

        $currentLines = explode("\n", rtrim($current, "\n"));
        $generatedLines = explode("\n", rtrim($contents, "\n"));
        $name = basename($path);

        return implode("\n", [
            '--- config/'.$name,
            '+++ generated/'.$name,
            sprintf('@@ -1,%d +1,%d @@', count($currentLines), count($generatedLines)),
            ...array_map(static fn (string $line): string => '-'.$line, $currentLines),
            ...array_map(static fn (string $line): string => '+'.$line, $generatedLines),
            '',
        ]);
    }

    /**
     * Atomically write a new destination or back up and replace an existing one.
     *
     * @return string|null The timestamped backup path when existing contents changed.
     */
    public function write(string $path, string $contents, bool $force = false): ?string
    {
        $backup = null;

        if ($this->filesystem->exists($path)) {
            if ($this->filesystem->get($path) === $contents) {
                return null;
            }

            if (! $force) {
                throw new RuntimeException('The suite configuration already exists; pass --force to replace it.');
            }

            $backup = $path.'.backup-'.now()->format('Ymd-His');

            if ($this->filesystem->exists($backup)) {
                throw new RuntimeException('The timestamped suite configuration backup already exists; retry after the timestamp changes.');
            }

            if (! $this->filesystem->copy($path, $backup)) {
                throw new RuntimeException('The existing suite configuration could not be backed up.');
            }
        }

        $this->filesystem->replace($path, $contents);

        return $backup;
    }

    /**
     * @param  list<string>  $values
     */
    private function renderList(array $values): string
    {
        if ($values === []) {
            return '[]';
        }

        return sprintf("['%s']", implode("', '", $values));
    }
}
