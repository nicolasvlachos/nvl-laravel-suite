<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Nvl\Auth\Providers\AuthServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Suite\Support\SuiteModuleCatalog;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 2).'/tools/check-release-changelogs.php';

it('keeps v1.0.5 release history under dated suite and every package heading', function (): void {
    $root = dirname(__DIR__, 2);
    $version = '1.0.5';
    $catalog = require $root.'/tools/package-family.php';
    $rootChangelog = file_get_contents($root.'/CHANGELOG.md');
    $authChangelog = file_get_contents($root.'/packages/nvl/auth/CHANGELOG.md');
    $mediaChangelog = file_get_contents($root.'/packages/nvl/media/CHANGELOG.md');
    $filterableChangelog = file_get_contents($root.'/packages/nvl/filterable/CHANGELOG.md');
    $supportChangelog = file_get_contents($root.'/packages/nvl/support/CHANGELOG.md');

    expect($rootChangelog)->toBeString()
        ->toContain("## [{$version}] - 2026-08-12")
        ->toContain('## [1.0.4] - 2026-08-12')
        ->not->toMatch('/target(?:ed)?(?: for|:) v?1\.0\.5/i')
        ->and($authChangelog)->toBeString()
        ->toContain("## [{$version}] - 2026-08-12")
        ->not->toMatch('/target(?:ed)?(?: for|:) v?1\.0\.5/i')
        ->and($mediaChangelog)->toBeString()
        ->toContain("## [{$version}] - 2026-08-12")
        ->not->toMatch('/target(?:ed)?(?: for|:) v?1\.0\.5/i')
        ->and(trim((string) releaseChangelogSection($rootChangelog, 'Unreleased')))
        ->toBe('')
        ->and(releaseChangelogSection($rootChangelog, $version))
        ->toContain('mass-assignable attributes', 'SQLite adoption constraints')
        ->and(trim((string) releaseChangelogSection($authChangelog, 'Unreleased')))
        ->toBe('')
        ->and(releaseChangelogSection($authChangelog, $version))
        ->toContain('$fillable')
        ->and(trim((string) releaseChangelogSection($mediaChangelog, 'Unreleased')))
        ->toBe('')
        ->and(releaseChangelogSection($mediaChangelog, $version))
        ->toContain('missing-binary incident recovery runbook')
        ->and($filterableChangelog)->toBeString()
        ->toContain('## [1.0.0] - 2026-08-08')
        ->not->toContain('## [1.0.0] - Unreleased')
        ->and($supportChangelog)->toBeString()
        ->toContain('## [1.0.0] - 2026-08-08')
        ->not->toContain('## [1.0.0] - Unreleased');

    foreach ($catalog['packages'] as $package) {
        $contents = file_get_contents($root.'/packages/nvl/'.$package.'/CHANGELOG.md');

        expect($contents)->toBeString()
            ->toContain("## [{$version}] - 2026-08-12")
            ->not->toMatch('/^## \[\d+\.\d+\.\d+\] - Unreleased$/m')
            ->and(trim((string) releaseChangelogSection($contents, 'Unreleased')))->toBe('');
    }
});

it('rejects a release whose notes remain under Unreleased or omit a changed package', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-release-changelog-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;

    try {
        $filesystem->mkdir($workspace.'/tools');
        $filesystem->mkdir($workspace.'/packages/nvl/auth');
        file_put_contents($workspace.'/tools/package-family.php', <<<'PHP'
<?php

return ['packages' => ['auth']];
PHP);
        file_put_contents($workspace.'/CHANGELOG.md', <<<'MARKDOWN'
# Changelog

## [Unreleased]

- Shipped but not archived.

## [1.2.3] - 2026-08-12
MARKDOWN);
        file_put_contents($workspace.'/packages/nvl/auth/CHANGELOG.md', <<<'MARKDOWN'
# Changelog

## [Unreleased]

## [1.2.3] - Unreleased
MARKDOWN);

        expect(releaseChangelogErrors($workspace, '1.2.3', ['auth']))
            ->toContain(
                'Release changelog [suite] must leave [Unreleased] blank when publishing [1.2.3].',
                'Release changelog [auth] has no dated [1.2.3] heading.',
                'Release changelog [auth] contains a stable version dated [Unreleased].',
            );
    } finally {
        $filesystem->remove($workspace);
    }
});

it('declares the repository root as the only installable package', function (): void {
    $manifest = suiteArchiveManifest();

    expect($manifest['name'] ?? null)->toBe('nvl/laravel-suite')
        ->and($manifest['type'] ?? null)->toBe('library')
        ->and($manifest['homepage'] ?? null)
        ->toBe('https://github.com/nicolasvlachos/nvl-laravel-suite')
        ->and($manifest['support']['issues'] ?? null)
        ->toBe('https://github.com/nicolasvlachos/nvl-laravel-suite/issues')
        ->and($manifest['support']['security'] ?? null)
        ->toBe('https://github.com/nicolasvlachos/nvl-laravel-suite/security/policy')
        ->and($manifest)->not->toHaveKey('repositories');
});

it('replaces every former package with the suite version', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = suiteArchiveManifest();
    $catalog = require $root.'/tools/package-family.php';
    $expected = array_fill_keys(
        array_map(static fn (string $package): string => 'nvl/'.$package, $catalog['packages']),
        'self.version',
    );
    ksort($expected);

    expect($manifest['replace'] ?? null)->toBe($expected);
});

it('autoloads every internal module from the suite archive layout', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = suiteArchiveManifest();
    $catalog = require $root.'/tools/package-family.php';

    foreach ($catalog['packages'] as $package) {
        $namespace = str_replace(' ', '', ucwords(str_replace('-', ' ', $package)));

        expect($manifest['autoload']['psr-4']['Nvl\\'.$namespace.'\\'] ?? null)
            ->toBe('packages/nvl/'.$package.'/src/');
    }

    expect($manifest['autoload']['psr-4'])->not->toHaveKey('Nvl\\Workbench\\')
        ->and($manifest['autoload-dev']['psr-4']['Nvl\\Workbench\\'] ?? null)->toBe('app/');
});

it('discovers one suite provider instead of twenty package providers', function (): void {
    $manifest = suiteArchiveManifest();

    expect($manifest['extra']['laravel']['providers'] ?? null)->toBe([
        'Nvl\\Suite\\SuiteServiceProvider',
    ]);
});

it('registers every module provider once and after its internal dependencies', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/package-family.php';
    $providerPackages = [];

    foreach ($catalog['packages'] as $package) {
        $manifest = json_decode(
            file_get_contents($root.'/packages/nvl/'.$package.'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $providers = $manifest['extra']['laravel']['providers'] ?? [];

        expect($providers)->toHaveCount(1);

        $providerPackages[$providers[0]] = $package;
    }

    $suiteCatalog = new SuiteModuleCatalog(new Repository([
        'nvl-suite' => ['modules' => []],
    ]));
    $definitions = $suiteCatalog->modules();
    $providers = array_column($definitions, 'provider');
    $registeredPackages = array_map(
        static fn (string $provider): string => $providerPackages[$provider],
        $providers,
    );

    expect($providers)->toHaveCount(count($catalog['packages']))
        ->and(array_unique($providers))->toHaveCount(count($providers))
        ->and(array_diff($catalog['packages'], $registeredPackages))->toBe([])
        ->and(array_diff($registeredPackages, $catalog['packages']))->toBe([]);

    $positions = array_flip($registeredPackages);

    foreach ($catalog['internal_dependencies'] as $package => $dependencies) {
        foreach ($dependencies as $dependency) {
            expect($positions[$dependency])->toBeLessThan($positions[$package]);
        }
    }
});

it('ships a complete staged-adoption module configuration', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/package-family.php';
    $configuration = require $root.'/config/nvl-suite.php';
    $configuredModules = array_keys($configuration['modules'] ?? []);
    $catalogModules = $catalog['packages'];
    sort($configuredModules);
    sort($catalogModules);

    $suiteCatalog = new SuiteModuleCatalog(new Repository([
        'nvl-suite' => ['modules' => $configuration['modules']],
    ]));
    $providerDependencies = array_map(
        static fn (array $definition): array => $definition['dependencies'],
        $suiteCatalog->modules(),
    );
    $catalogDependencies = $catalog['internal_dependencies'];
    ksort($providerDependencies);
    ksort($catalogDependencies);
    $typescriptModules = array_keys(array_filter(
        $suiteCatalog->modules(),
        static fn (array $definition): bool => $definition['typescript'],
    ));
    $statefulModules = array_keys(array_filter(
        $suiteCatalog->modules(),
        static fn (array $definition): bool => $definition['stateful'],
    ));
    sort($typescriptModules);
    sort($statefulModules);
    sort($catalog['typescript_sources']);
    sort($catalog['stateful']);

    expect($configuredModules)->toBe($catalogModules)
        ->and(array_filter(
            $configuration['modules'],
            static fn (mixed $enabled): bool => $enabled !== true,
        ))->toBe([])
        ->and($providerDependencies)->toBe($catalogDependencies)
        ->and($typescriptModules)->toBe($catalog['typescript_sources'])
        ->and($statefulModules)->toBe($catalog['stateful']);

    expect($catalog['typescript_sources'])
        ->toContain('data', 'mail-notifications')
        ->each->toBeIn($catalogModules);
});

it('selects only an enabled module and its transitive dependencies', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/package-family.php';
    $modules = array_fill_keys($catalog['packages'], false);
    $modules['auth'] = true;
    $suiteCatalog = new SuiteModuleCatalog(new Repository([
        'nvl-suite' => ['modules' => $modules],
    ]));

    expect($suiteCatalog->effectiveProviders())->toBe([
        DataServiceProvider::class,
        AuthServiceProvider::class,
    ]);
});

it('rejects invalid staged-adoption module configuration', function (array $modules, string $message): void {
    $suiteCatalog = new SuiteModuleCatalog(new Repository([
        'nvl-suite' => ['modules' => $modules],
    ]));

    expect(fn (): array => $suiteCatalog->effectiveProviders())
        ->toThrow(RuntimeException::class, $message);
})->with([
    'non-boolean flag' => [['auth' => null], 'Suite module [auth] must be configured with a boolean flag.'],
    'unknown module' => [['authentication' => true], 'Unknown suite module configuration: authentication.'],
]);

it('builds exactly one versioned Composer archive', function (): void {
    [$workspace, $archive] = suiteArchiveBuild();

    try {
        expect(basename($archive))->toBe('nvl-laravel-suite-1.2.3.zip');

        $manifest = suiteArchiveReadManifest($archive);

        expect($manifest['name'] ?? null)->toBe('nvl/laravel-suite')
            ->and($manifest['version'] ?? null)->toBeNull();
    } finally {
        expect($workspace)->toBeDirectory();
    }
});

it('ships every module and the central provider in the archive', function (): void {
    [$workspace, $archive] = suiteArchiveBuild();

    try {
        $entries = suiteArchiveEntries($archive);
        $catalog = require dirname(__DIR__, 2).'/tools/package-family.php';

        expect($entries)->toContain(
            'src/SuiteServiceProvider.php',
            'src/Console/Commands/SuiteConfigurationCommand.php',
            'src/Console/Commands/SuiteDoctorCommand.php',
            'src/Console/Commands/SuiteSkillsDoctorCommand.php',
            'src/Console/Commands/SuiteSkillsPublishCommand.php',
            'src/Services/SuiteConfigurationInspector.php',
            'src/Services/SuiteSkillManager.php',
            'src/Support/SuiteModuleCatalog.php',
            'docs/adoption-matrix.md',
            'docs/installation-profiles.md',
        );

        foreach ($catalog['packages'] as $package) {
            expect(array_filter(
                $entries,
                static fn (string $entry): bool => str_starts_with(
                    $entry,
                    'packages/nvl/'.$package.'/src/',
                ),
            ))->not->toBe([]);
        }
    } finally {
        expect($workspace)->toBeDirectory();
    }
});

it('validates release changelogs from the materialized suite archive', function (): void {
    [$workspace, $archive] = suiteArchiveBuild();
    $extracted = sys_get_temp_dir().'/nvl-suite-changelog-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;

    try {
        $filesystem->mkdir($extracted);
        $zip = new ZipArchive;

        expect($zip->open($archive))->toBeTrue()
            ->and($zip->extractTo($extracted))->toBeTrue();

        $zip->close();

        $catalog = require dirname(__DIR__, 2).'/tools/package-family.php';

        expect(releaseChangelogErrors($extracted, '1.0.5', $catalog['packages']))->toBe([]);
    } finally {
        $filesystem->remove($extracted);
        expect($workspace)->toBeDirectory();
    }
});

it('ships the scheduler, SQLite, and Media recovery contracts in the archive', function (): void {
    [$workspace, $archive] = suiteArchiveBuild();

    try {
        $rootReadme = suiteArchiveRead($archive, 'README.md');
        $activityReadme = suiteArchiveRead($archive, 'packages/nvl/activity/README.md');
        $mailReadme = suiteArchiveRead($archive, 'packages/nvl/mail-notifications/README.md');
        $mediaCommands = suiteArchiveRead($archive, 'packages/nvl/media/docs/commands.md');

        expect($rootReadme)->toContain(
            'nvl:activity:purge-system',
            'nvl:mail-notifications:process-scheduled',
            'nvl:mail-notifications:recover-scheduled',
            'nvl:media:multipart:prune',
            'onOneServer()',
            'withoutOverlapping()',
            'shared lock store',
            'SQLite adoption constraints',
            'QueryException',
            '`expired`',
        )
            ->and($activityReadme)->toContain(
                'registers `nvl:activity:purge-system`',
                'same canonical shared lock backend',
            )
            ->and($mailReadme)->toContain(
                'The package never registers them or chooses their cadence',
                'one shared lock store',
            )
            ->and($mediaCommands)->toContain(
                'nvl:media:doctor --strict --format=json',
                'nvl:media:reconcile --production --orphans',
                'original object whenever possible',
                'never update a persisted path directly',
                'explicit business decision',
                'Never automate',
                '`nvl:media:reconcile --cleanup-orphans` as a replacement',
            );
    } finally {
        expect($workspace)->toBeDirectory();
    }
});

it('ships every package consumption asset and native Boost skill', function (): void {
    [$workspace, $archive] = suiteArchiveBuild();

    try {
        $root = dirname(__DIR__, 2);
        $entries = suiteArchiveEntries($archive);
        $entryLookup = array_fill_keys($entries, true);
        $catalog = require $root.'/tools/package-family.php';
        $distributionDirectories = ['config', 'database/migrations', 'docs', 'lang', 'resources', 'src'];
        $distributionFiles = [
            'CHANGELOG.md',
            'CONTRIBUTING.md',
            'LICENSE',
            'README.md',
            'SECURITY.md',
            'UPGRADING.md',
        ];

        foreach ($catalog['packages'] as $package) {
            $packagePrefix = 'packages/nvl/'.$package.'/';

            foreach ($distributionFiles as $file) {
                expect($entryLookup)->toHaveKey($packagePrefix.$file);
            }

            foreach ($distributionDirectories as $directory) {
                $source = $root.'/'.$packagePrefix.$directory;

                if (! is_dir($source)) {
                    continue;
                }

                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                    $source,
                    FilesystemIterator::SKIP_DOTS,
                ));

                foreach ($files as $file) {
                    if (! $file->isFile() || in_array($file->getFilename(), ['.DS_Store', '.gitkeep'], true)) {
                        continue;
                    }

                    $relative = substr($file->getPathname(), strlen($root) + 1);

                    expect($entryLookup)->toHaveKey(
                        $relative,
                        "Archive is missing package consumption asset [{$relative}].",
                    );
                }
            }

            $skillPrefix = 'resources/boost/skills/nvl-'.$package.'/';
            $packageSkillPrefix = $packagePrefix.'resources/boost/skills/nvl-'.$package.'/';
            $skillEntries = array_values(array_filter(
                $entries,
                static fn (string $entry): bool => str_starts_with($entry, $skillPrefix),
            ));

            expect($skillEntries)->not->toBeEmpty();

            foreach ($skillEntries as $skillEntry) {
                $relative = substr($skillEntry, strlen($skillPrefix));
                $packageSkillEntry = $packageSkillPrefix.$relative;

                expect($entryLookup)->toHaveKey($packageSkillEntry)
                    ->and(suiteArchiveRead($archive, $skillEntry))
                    ->toBe(suiteArchiveRead($archive, $packageSkillEntry));
            }
        }
    } finally {
        expect($workspace)->toBeDirectory();
    }
});

it('rejects development-only content from the suite archive', function (): void {
    [$workspace, $archive] = suiteArchiveBuild();

    try {
        $entries = suiteArchiveEntries($archive);
        $topLevelEntries = array_values(array_unique(array_map(
            static fn (string $entry): string => explode('/', $entry, 2)[0],
            $entries,
        )));
        sort($topLevelEntries);
        $expectedTopLevelEntries = file(
            dirname(__DIR__, 2).'/tools/release-archive-top-level.txt',
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
        );

        expect($expectedTopLevelEntries)->toBeArray();

        sort($expectedTopLevelEntries);
        $developmentEntries = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => preg_match(
                '#(^|/)(vendor|node_modules|tests?|build|tmp|\\.temp|\\.phpunit\\.cache)(/|$)|(^|/)(phpunit[^/]*\\.xml[^/]*|phpstan[^/]*\\.neon[^/]*|composer-dependency-analyser\\.php|composer-require-checker\\.json)$|(^|/)\\.(DS_Store|gitattributes|gitignore|gitkeep)$#',
                $entry,
            ) === 1,
        ));
        $nestedManifests = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => preg_match(
                '#^packages/nvl/[^/]+/composer\\.json$#',
                $entry,
            ) === 1,
        ));

        expect($topLevelEntries)->toBe($expectedTopLevelEntries)
            ->and($developmentEntries)->toBe([])
            ->and($nestedManifests)->toBe([]);
    } finally {
        expect($workspace)->toBeDirectory();
    }
});

it('keeps every relative README link valid inside the suite archive', function (): void {
    [$workspace, $archive] = suiteArchiveBuild();

    try {
        $entries = suiteArchiveEntries($archive);
        $entryLookup = array_fill_keys($entries, true);
        $readmes = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry === 'README.md'
                || preg_match('#^packages/nvl/[^/]+/README\\.md$#', $entry) === 1,
        ));

        foreach ($readmes as $readme) {
            $contents = suiteArchiveRead($archive, $readme);
            preg_match_all(
                '~\\[[^]]+\\]\\((?!https?://|mailto:|#)([^)#]+)(?:#[^)]*)?\\)~',
                $contents,
                $matches,
            );

            foreach (array_unique($matches[1] ?? []) as $link) {
                $target = ltrim(Path::canonicalize(dirname($readme).'/'.$link), './');

                expect($entryLookup)->toHaveKey(
                    $target,
                    "Archive README [{$readme}] links to missing file [{$target}].",
                );
            }
        }
    } finally {
        expect($workspace)->toBeDirectory();
    }
});

it('publishes clean Packagist tags without a custom Composer repository', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-release.yml');
    $serializedWorkflow = json_encode($workflow, JSON_THROW_ON_ERROR);

    expect($serializedWorkflow)->toContain(
        'git read-tree --empty',
        'git write-tree',
        'git commit-tree',
        'git push origin',
    )->not->toContain(
        'build-archive-repository.php',
        'build-public-composer-repository.php',
        'inspect-package-archive.php',
        'deploy-pages',
        'upload-pages-artifact',
    )
        ->and($root.'/tools/build-archive-repository.php')->not->toBeFile()
        ->and($root.'/tools/build-public-composer-repository.php')->not->toBeFile()
        ->and($root.'/tools/inspect-package-archive.php')->not->toBeFile();
});

/**
 * @return array<string, mixed>
 */
function suiteArchiveManifest(): array
{
    return json_decode(
        file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * @return array{string, string}
 */
function suiteArchiveBuild(): array
{
    static $archiveFixture;

    if (is_array($archiveFixture)) {
        return $archiveFixture;
    }

    $root = dirname(__DIR__, 2);
    $workspace = sys_get_temp_dir().'/nvl-suite-archive-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $sourceWorktreesDirectory = $root.'/.worktrees';
    $sourceWorktreesDirectoryExisted = is_dir($sourceWorktreesDirectory);
    $sourceWorktreeFixture = $sourceWorktreesDirectory.'/archive-fixture-'.bin2hex(random_bytes(8));
    $filesystem->mkdir($workspace);
    $filesystem->dumpFile($sourceWorktreeFixture.'/sentinel.txt', 'development-only');

    $process = new Process(
        ['composer', 'archive', '--format=zip', '--dir='.$workspace, '--no-interaction'],
        $root,
        ['COMPOSER_ROOT_VERSION' => '1.2.3'],
    );
    $process->setTimeout(90);

    try {
        $process->run();
    } finally {
        $filesystem->remove($sourceWorktreeFixture);

        if (! $sourceWorktreesDirectoryExisted && is_dir($sourceWorktreesDirectory)) {
            rmdir($sourceWorktreesDirectory);
        }
    }

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $archives = glob($workspace.'/nvl-laravel-suite-*.zip');

    expect($archives)->toBeArray()->toHaveCount(1);

    register_shutdown_function(static function () use ($workspace): void {
        (new Filesystem)->remove($workspace);
    });

    $archiveFixture = [$workspace, $archives[0]];

    return $archiveFixture;
}

/**
 * @return array<string, mixed>
 */
function suiteArchiveReadManifest(string $archive): array
{
    return json_decode(
        suiteArchiveRead($archive, 'composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function suiteArchiveRead(string $archive, string $entry): string
{
    $zip = new ZipArchive;
    expect($zip->open($archive))->toBeTrue();

    try {
        $contents = $zip->getFromName($entry);

        expect($contents)->toBeString();

        return $contents;
    } finally {
        $zip->close();
    }
}

/**
 * @return list<string>
 */
function suiteArchiveEntries(string $archive): array
{
    $zip = new ZipArchive;
    expect($zip->open($archive))->toBeTrue();

    try {
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name)) {
                $entries[] = $name;
            }
        }

        return $entries;
    } finally {
        $zip->close();
    }
}
