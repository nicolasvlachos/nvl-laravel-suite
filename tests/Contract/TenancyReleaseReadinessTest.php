<?php

declare(strict_types=1);

it('declares the complete 21 package tenancy release closure', function (): void {
    $root = dirname(__DIR__, 2);
    $family = require $root.'/tools/package-family.php';

    expect($family['packages'])->toHaveCount(21)
        ->and($family['tenancy_release']['package_count'])->toBe(21)
        ->and($family['tenancy_release']['profiles'])->toBe([
            'disabled',
            'full-package-auth',
            'host-uuid-custom-principals',
            'standalone-media-no-auth',
            'standalone-taxonomy-no-auth',
        ])
        ->and($family['tenancy_release']['distribution_assertions'])->toContain(
            'archive-closure',
            'provider-discovery',
            'module-config-closure',
            'source-skill-sync',
            'typescript-inputs',
            'public-contract-inputs',
            'composer-validation',
            'dependency-audit',
        );

    foreach ($family['packages'] as $package) {
        $path = $root.'/packages/nvl/'.$package;
        expect($path.'/composer.json')->toBeFile()
            ->and($path.'/README.md')->toBeFile()
            ->and($path.'/UPGRADING.md')->toBeFile()
            ->and($path.'/CHANGELOG.md')->toBeFile();
    }
});

it('keeps the release workflow gated on the sealed adoption proof', function (): void {
    $root = dirname(__DIR__, 2);
    $quality = (string) file_get_contents($root.'/.github/workflows/package-quality.yml');
    $release = (string) file_get_contents($root.'/.github/workflows/package-release.yml');

    expect($quality.$release)->toContain(
        'tenancy-release-adoption',
        'RUN_TENANCY_PRODUCTION_CONSUMER: 1',
        'tests/Contract/TenancyReleaseReadinessTest.php',
        'TENANCY_CONSUMER_TAXONOMY_DATABASE',
    );
});
