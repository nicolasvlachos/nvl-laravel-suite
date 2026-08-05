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
}

return $configuration;
