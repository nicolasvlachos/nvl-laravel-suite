<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Nvl\Auth\Providers\AuthServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Suite\SuiteServiceProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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

    $providerConstant = (new ReflectionClass(SuiteServiceProvider::class))
        ->getReflectionConstant('PROVIDERS');

    expect($providerConstant)->not->toBeFalse();

    $providers = $providerConstant->getValue();
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

    $dependencies = (new ReflectionClass(SuiteServiceProvider::class))
        ->getReflectionConstant('DEPENDENCIES');

    if ($dependencies === false) {
        throw new RuntimeException('Suite dependency metadata is missing.');
    }

    $providerDependencies = $dependencies->getValue();
    $catalogDependencies = $catalog['internal_dependencies'];
    ksort($providerDependencies);
    ksort($catalogDependencies);

    expect($configuredModules)->toBe($catalogModules)
        ->and(array_filter(
            $configuration['modules'],
            static fn (mixed $enabled): bool => $enabled !== true,
        ))->toBe([])
        ->and($providerDependencies)->toBe($catalogDependencies);
});

it('selects only an enabled module and its transitive dependencies', function (): void {
    $root = dirname(__DIR__, 2);
    $catalog = require $root.'/tools/package-family.php';
    $modules = array_fill_keys($catalog['packages'], false);
    $modules['auth'] = true;
    $provider = new SuiteServiceProvider(new Application($root));
    $method = (new ReflectionClass($provider))->getMethod('selectedProviders');
    $selected = $method->invoke($provider, new Repository([
        'nvl-suite' => ['modules' => $modules],
    ]));

    expect($selected)->toBe([
        DataServiceProvider::class,
        AuthServiceProvider::class,
    ]);
});

it('rejects invalid staged-adoption module configuration', function (array $modules, string $message): void {
    $root = dirname(__DIR__, 2);
    $provider = new SuiteServiceProvider(new Application($root));
    $method = (new ReflectionClass($provider))->getMethod('selectedProviders');

    expect(fn (): array => $method->invoke($provider, new Repository([
        'nvl-suite' => ['modules' => $modules],
    ])))->toThrow(RuntimeException::class, $message);
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

        expect($entries)->toContain('src/SuiteServiceProvider.php');

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
    (new Filesystem)->mkdir($workspace);

    $process = new Process(
        ['composer', 'archive', '--format=zip', '--dir='.$workspace, '--no-interaction'],
        $root,
        ['COMPOSER_ROOT_VERSION' => '1.2.3'],
    );
    $process->setTimeout(90);
    $process->run();

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
