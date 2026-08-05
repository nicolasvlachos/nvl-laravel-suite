<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('stamps versions so relocated archives install through a Composer artifact repository', function (): void {
    $root = dirname(__DIR__, 2);
    $workspace = sys_get_temp_dir().'/nvl-archive-repository-'.bin2hex(random_bytes(8));
    $repository = $workspace.'/repository';
    $relocatedRepository = $workspace.'/relocated';
    $consumer = $workspace.'/consumer';
    $filesystem = new Filesystem;
    $filesystem->mkdir([$repository, $consumer]);

    try {
        foreach (['activity', 'data', 'support'] as $package) {
            packageArchiveWriteZip(
                $repository."/nvl-{$package}-1.2.3.zip",
                packageArchiveManifest($package),
                packageArchiveEntries($package),
            );
        }

        $process = new Process([
            PHP_BINARY,
            $root.'/tools/build-archive-repository.php',
            $repository,
            '1.2.3',
        ]);
        $process->setTimeout(10);
        $process->run();

        expect($process->getExitCode())->toBe(0);

        $filesystem->rename($repository, $relocatedRepository);
        $filesystem->dumpFile($consumer.'/composer.json', json_encode([
            'name' => 'nvl/archive-consumer',
            'require' => new stdClass,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $configureRepository = packageArchiveComposer($consumer, $workspace, [
            'config',
            'repositories.nvl',
            'artifact',
            $relocatedRepository,
        ]);
        $disablePackagist = packageArchiveComposer($consumer, $workspace, [
            'config',
            'repo.packagist',
            'false',
        ]);
        $install = packageArchiveComposer($consumer, $workspace, [
            'require',
            '--no-audit',
            '--no-interaction',
            '--no-plugins',
            '--no-progress',
            '--no-scripts',
            '--prefer-dist',
            'nvl/activity:1.2.3',
        ]);

        expect($configureRepository->getExitCode())->toBe(0)
            ->and($disablePackagist->getExitCode())->toBe(0)
            ->and($install->getExitCode())->toBe(0, $install->getErrorOutput())
            ->and($consumer.'/vendor/nvl/activity/composer.json')->toBeFile()
            ->and($consumer.'/vendor/nvl/data/composer.json')->toBeFile()
            ->and($consumer.'/vendor/nvl/support/composer.json')->toBeFile();

        $installedManifestContents = file_get_contents(
            $consumer.'/vendor/nvl/activity/composer.json',
        );

        expect($installedManifestContents)->toBeString();

        $installedManifest = json_decode(
            $installedManifestContents,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($installedManifest['name'] ?? null)->toBe('nvl/activity')
            ->and($installedManifest['version'] ?? null)->toBe('1.2.3')
            ->and($consumer.'/vendor/nvl/activity/src/Example.php')->toBeFile();
    } finally {
        $filesystem->remove($workspace);
    }
});

it('accepts a complete Activity archive with exact root assets and autoload declarations', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-activity-archive-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($workspace);
    $archive = $workspace.'/nvl-activity-1.0.0.zip';

    try {
        packageArchiveWriteZip(
            $archive,
            packageArchiveManifest('activity'),
            packageArchiveEntries('activity'),
        );

        $process = packageArchiveInspect($archive, 'activity');

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain(
                'Archive for nvl/activity contains all required assets.',
            );
    } finally {
        $filesystem->remove($workspace);
    }
});

it('does not accept nested paths as archive-root package assets', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-nested-archive-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($workspace);
    $archive = $workspace.'/nvl-support-1.0.0.zip';
    $entries = packageArchiveEntries('support');
    unset($entries['src/Example.php']);
    $entries['nested/src/Example.php'] = '<?php';

    try {
        packageArchiveWriteZip($archive, packageArchiveManifest('support'), $entries);

        $process = packageArchiveInspect($archive, 'support');

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Archive is missing: src');
    } finally {
        $filesystem->remove($workspace);
    }
});

it('requires the archive manifest to declare the expected NVL package', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-package-name-archive-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($workspace);
    $archive = $workspace.'/nvl-activity-1.0.0.zip';
    $manifest = packageArchiveManifest('activity');
    $manifest['name'] = 'nvl/support';

    try {
        packageArchiveWriteZip($archive, $manifest, packageArchiveEntries('activity'));

        $process = packageArchiveInspect($archive, 'activity');

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('composer.json must declare package [nvl/activity]');
    } finally {
        $filesystem->remove($workspace);
    }
});

it('rejects development and repository files from package archives', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-development-files-archive-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($workspace);
    $archive = $workspace.'/nvl-support-1.0.0.zip';
    $entries = [
        ...packageArchiveEntries('support'),
        '.gitattributes' => '/tests export-ignore',
        '.gitignore' => '/vendor',
        'composer.lock' => '{}',
        'phpstan.neon.dist' => 'parameters: {}',
        'phpunit.xml.dist' => '<phpunit/>',
        'tests/Feature/SupportPackageTest.php' => '<?php',
        'vendor/autoload.php' => '<?php',
    ];

    try {
        packageArchiveWriteZip($archive, packageArchiveManifest('support'), $entries);

        $process = packageArchiveInspect($archive, 'support');

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain(
                '.gitattributes',
                '.gitignore',
                'composer.lock',
                'phpstan.neon.dist',
                'phpunit.xml.dist',
                'tests/Feature/SupportPackageTest.php',
                'vendor/autoload.php',
            );
    } finally {
        $filesystem->remove($workspace);
    }
});

it('validates canonical and file-based autoload declarations', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-autoload-archive-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($workspace);
    $archive = $workspace.'/nvl-support-1.0.0.zip';
    $manifest = packageArchiveManifest('support');
    $manifest['autoload'] = [
        'files' => ['src/Support/missing.php'],
        'psr-4' => [
            'Nvl\\Unexpected\\' => 'src/',
        ],
    ];

    try {
        packageArchiveWriteZip($archive, $manifest, packageArchiveEntries('support'));

        $process = packageArchiveInspect($archive, 'support');
        $errors = $process->getErrorOutput();

        expect($process->getExitCode())->toBe(1)
            ->and($errors)->toContain(
                'autoload.psr-4 must map [Nvl\Support\] to [src/]',
                'autoload.files path [src/Support/missing.php] is missing from the archive root',
            );
    } finally {
        $filesystem->remove($workspace);
    }
});

it('requires every Activity runtime asset at the archive root', function (): void {
    $workspace = sys_get_temp_dir().'/nvl-activity-assets-'.bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->mkdir($workspace);
    $archive = $workspace.'/nvl-activity-1.0.0.zip';
    $entries = packageArchiveEntries('activity');

    foreach ([
        'config/activity.php',
        'lang/en/activity/general.php',
        'lang/bg/activity/general.php',
        'routes/api.php',
        'src/Support/activitylog_compatibility.php',
    ] as $runtimeAsset) {
        unset($entries[$runtimeAsset]);
    }

    try {
        packageArchiveWriteZip($archive, packageArchiveManifest('activity'), $entries);

        $process = packageArchiveInspect($archive, 'activity');

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain(
                'config/activity.php',
                'lang/en/activity/general.php',
                'lang/bg/activity/general.php',
                'routes/api.php',
                'src/Support/activitylog_compatibility.php',
            );
    } finally {
        $filesystem->remove($workspace);
    }
});

/**
 * Build the package metadata used by isolated archive fixtures.
 *
 * @return array{
 *     name: string,
 *     require?: array<string, string>,
 *     autoload: array{psr-4: array<string, string>, files?: list<string>}
 * }
 */
function packageArchiveManifest(string $package): array
{
    $namespace = implode('', array_map(
        static fn (string $segment): string => ucfirst($segment),
        explode('-', $package),
    ));
    $autoload = [
        'psr-4' => [
            "Nvl\\{$namespace}\\" => 'src/',
        ],
    ];

    if ($package === 'activity') {
        $autoload['files'] = ['src/Support/activitylog_compatibility.php'];
    }

    $manifest = [
        'name' => "nvl/{$package}",
        'autoload' => $autoload,
    ];

    if ($package === 'activity') {
        $manifest['require'] = [
            'nvl/data' => '^1.0',
            'nvl/support' => '^1.0',
        ];
    }

    return $manifest;
}

/**
 * Build the exact root file inventory used by isolated archive fixtures.
 *
 * @return array<string, string>
 */
function packageArchiveEntries(string $package): array
{
    $entries = [
        'README.md' => '# Package',
        'LICENSE' => 'MIT',
        'CHANGELOG.md' => '# Changelog',
        'UPGRADING.md' => '# Upgrading',
        'SECURITY.md' => '# Security',
        'CONTRIBUTING.md' => '# Contributing',
        'src/Example.php' => '<?php',
        "resources/boost/skills/nvl-{$package}/SKILL.md" => '# Skill',
        "resources/boost/skills/nvl-{$package}/agents/openai.yaml" => 'name: package',
    ];

    if ($package === 'activity') {
        $entries = [
            ...$entries,
            'config/activity.php' => '<?php return [];',
            'database/migrations/2026_01_01_000000_create_activity_log_table.php' => '<?php',
            'lang/en/activity/general.php' => '<?php return [];',
            'lang/bg/activity/general.php' => '<?php return [];',
            'routes/api.php' => '<?php',
            'src/Support/activitylog_compatibility.php' => '<?php',
        ];
    }

    return $entries;
}

/**
 * Write one deterministic package ZIP fixture.
 *
 * @param  array<string, mixed>  $manifest
 * @param  array<string, string>  $entries
 */
function packageArchiveWriteZip(string $archive, array $manifest, array $entries): void
{
    $zip = new ZipArchive;
    $result = $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($result !== true) {
        throw new RuntimeException("Unable to create archive fixture [{$archive}].");
    }

    $manifestContents = json_encode(
        $manifest,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    );

    if (! $zip->addFromString('composer.json', $manifestContents)) {
        throw new RuntimeException('Unable to add composer.json to the archive fixture.');
    }

    foreach ($entries as $path => $contents) {
        if (! $zip->addFromString($path, $contents)) {
            throw new RuntimeException("Unable to add [{$path}] to the archive fixture.");
        }
    }

    if (! $zip->close()) {
        throw new RuntimeException("Unable to close archive fixture [{$archive}].");
    }
}

/**
 * Run the package archive inspector against one isolated fixture.
 */
function packageArchiveInspect(string $archive, string $package): Process
{
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 2).'/tools/inspect-package-archive.php',
        $archive,
        $package,
    ]);
    $process->setTimeout(10);
    $process->run();

    return $process;
}

/**
 * Run one isolated Composer command for the relocated archive consumer.
 *
 * @param  list<string>  $arguments
 */
function packageArchiveComposer(string $consumer, string $workspace, array $arguments): Process
{
    $process = new Process(
        ['composer', ...$arguments],
        $consumer,
        [
            'COMPOSER_HOME' => $workspace.'/composer-home',
            'COMPOSER_NO_AUDIT' => '1',
        ],
    );
    $process->setTimeout(30);
    $process->run();

    return $process;
}
