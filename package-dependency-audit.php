<?php

declare(strict_types=1);

$workspacePath = __DIR__;
$packageDirectories = glob($workspacePath.'/packages/nvl/*', GLOB_ONLYDIR);

if ($packageDirectories === false || $packageDirectories === []) {
    fwrite(STDERR, "No NVL package directories were found.\n");

    exit(1);
}

foreach ($packageDirectories as $packageDirectory) {
    $packageName = basename($packageDirectory);
    $packageVendorPath = $packageDirectory.'/vendor';

    if (file_exists($packageVendorPath) || is_link($packageVendorPath)) {
        fwrite(STDERR, "Refusing to replace existing path [{$packageVendorPath}].\n");

        exit(2);
    }

    if (! symlink('../../../vendor', $packageVendorPath)) {
        fwrite(STDERR, "Unable to expose the monorepo vendor directory to [nvl/{$packageName}].\n");

        exit(2);
    }

    $analysisExitCode = 0;

    try {
        putenv("NVL_PACKAGE_NAME={$packageName}");

        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($workspacePath.'/vendor/shipmonk/composer-dependency-analyser/bin/composer-dependency-analyser'),
            '--composer-json',
            escapeshellarg($packageDirectory.'/composer.json'),
            '--config',
            escapeshellarg($workspacePath.'/composer-dependency-analyser.php'),
            '--ignore-unused-deps',
        ]);

        fwrite(STDOUT, "Checking nvl/{$packageName}\n");
        passthru($command, $analysisExitCode);
    } finally {
        putenv('NVL_PACKAGE_NAME');

        if (is_link($packageVendorPath) && ! unlink($packageVendorPath)) {
            fwrite(STDERR, "Unable to remove temporary link [{$packageVendorPath}].\n");

            exit(2);
        }
    }

    if ($analysisExitCode !== 0) {
        exit($analysisExitCode);
    }
}
