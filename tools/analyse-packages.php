<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$catalog = require __DIR__.'/package-family.php';
$packages = $catalog['packages'];

foreach ($packages as $package) {
    $packageDirectory = $root.'/packages/nvl/'.$package;
    $vendorLink = $packageDirectory.'/vendor';

    if (file_exists($vendorLink) || is_link($vendorLink)) {
        fwrite(STDERR, "Refusing to replace existing package vendor path [{$vendorLink}].\n");

        exit(1);
    }

    if (! symlink('../../../vendor', $vendorLink)) {
        fwrite(STDERR, "Unable to link the root dependencies for [nvl/{$package}].\n");

        exit(1);
    }

    $originalDirectory = getcwd();
    $analysisExitCode = 0;

    try {
        if (! chdir($packageDirectory)) {
            throw new RuntimeException("Unable to enter package directory [{$packageDirectory}].");
        }

        fwrite(STDOUT, "\nAnalysing nvl/{$package}\n");
        passthru(
            'vendor/bin/phpstan analyse'.
            ' -c phpstan.neon.dist'.
            ' --debug'.
            ' --no-progress'.
            ' --error-format=table'.
            ' --memory-limit=3G',
            $exitCode,
        );

        if ($exitCode !== 0) {
            $analysisExitCode = $exitCode;
        }
    } finally {
        if (is_string($originalDirectory)) {
            chdir($originalDirectory);
        }

        if (is_link($vendorLink)) {
            unlink($vendorLink);
        }
    }

    if ($analysisExitCode !== 0) {
        exit($analysisExitCode);
    }
}

fwrite(STDOUT, "\nAll NVL packages pass PHPStan at level max.\n");
