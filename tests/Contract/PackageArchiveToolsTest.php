<?php

declare(strict_types=1);

use Nvl\Suite\SuiteServiceProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('declares the repository root as the only installable package', function (): void {
    $manifest = suiteArchiveManifest();

    expect($manifest['name'] ?? null)->toBe('nvl/laravel-suite')
        ->and($manifest['type'] ?? null)->toBe('library')
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
        $developmentEntries = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => preg_match(
                '#(^|/)(vendor|node_modules|tests|\\.temp)(/|$)#',
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

        expect($developmentEntries)->toBe([])
            ->and($nestedManifests)->toBe([]);
    } finally {
        expect($workspace)->toBeDirectory();
    }
});

it('keeps release automation free of the retired package repository tools', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root.'/.github/workflows/package-release.yml');
    $serializedWorkflow = json_encode($workflow, JSON_THROW_ON_ERROR);

    expect($serializedWorkflow)->not->toContain(
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
    $process->setTimeout(30);
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
    $zip = new ZipArchive;
    expect($zip->open($archive))->toBeTrue();

    try {
        $contents = $zip->getFromName('composer.json');

        expect($contents)->toBeString();

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
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
