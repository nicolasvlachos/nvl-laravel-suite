<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;
use Symfony\Component\Finder\Finder;

$packageName = getenv('NVL_PACKAGE_NAME');

$configuration = (new Configuration)
    ->disableReportingUnmatchedIgnores()
    ->ignoreErrorsOnExtension('ext-zip', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackage('orchestra/testbench-core', [ErrorType::SHADOW_DEPENDENCY]);

if (is_string($packageName) && $packageName !== '') {
    $configuration->ignoreErrorsOnPackage(
        'nvl/'.$packageName,
        [ErrorType::SHADOW_DEPENDENCY],
    );

    if ($packageName === 'settings') {
        $configuration->addForceUsedSymbol(Finder::class);
    }

    if ($packageName === 'auth') {
        $configuration
            ->ignoreErrorsOnPackageAndPath(
                'laravel/sanctum',
                __DIR__.'/packages/nvl/auth/src/Adapters/ApiTokens/SanctumApiTokenManager.php',
                [ErrorType::DEV_DEPENDENCY_IN_PROD],
            )
            ->ignoreErrorsOnPackageAndPath(
                'laravel/socialite',
                __DIR__.'/packages/nvl/auth/src/Adapters/Socialite/SocialiteIdentityProvider.php',
                [ErrorType::DEV_DEPENDENCY_IN_PROD],
            );
    }

    if ($packageName === 'media') {
        $testPath = __DIR__.'/packages/nvl/media/tests';
        $configuration
            ->ignoreErrorsOnExtensionAndPath(
                'ext-pdo',
                $testPath,
                [ErrorType::SHADOW_DEPENDENCY],
            )
            ->ignoreErrorsOnPackagesAndPaths(
                ['symfony/console', 'symfony/filesystem', 'symfony/process'],
                [$testPath],
                [ErrorType::SHADOW_DEPENDENCY],
            );
    }

    if ($packageName === 'taxonomy') {
        $configuration->ignoreErrorsOnExtensionAndPath(
            'ext-pcntl',
            __DIR__.'/packages/nvl/taxonomy/tests',
            [ErrorType::SHADOW_DEPENDENCY],
        );
    }

    if ($packageName === 'translatable') {
        $testPath = __DIR__.'/packages/nvl/translatable/tests';
        $configuration
            ->ignoreErrorsOnExtensionsAndPaths(
                ['ext-pdo', 'ext-redis'],
                [$testPath],
                [ErrorType::SHADOW_DEPENDENCY],
            )
            ->ignoreErrorsOnPackagesAndPaths(
                ['symfony/console', 'symfony/filesystem', 'symfony/process'],
                [$testPath],
                [ErrorType::SHADOW_DEPENDENCY],
            );
    }
}

return $configuration;
