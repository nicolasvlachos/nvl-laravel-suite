<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Seo\Http\Controllers\SeoManagementController;
use Nvl\Seo\Support\SeoRouteConfiguration;

$configuredMiddleware = config('seo.management.middleware', ['api', 'auth']);

if (! is_array($configuredMiddleware) || $configuredMiddleware === []) {
    throw new InvalidArgumentException(
        'Enabled SEO management routes require a non-empty middleware array.',
    );
}

$middleware = [];

foreach ($configuredMiddleware as $entry) {
    if (! is_string($entry) || $entry === '') {
        throw new InvalidArgumentException(
            'Every SEO management route middleware entry must be a non-empty string.',
        );
    }

    $middleware[] = $entry;
}

Route::middleware($middleware)
    ->prefix(SeoRouteConfiguration::managementPath())
    ->name(SeoRouteConfiguration::managementName())
    ->group(function (): void {
        Route::get('/profiles/status', [SeoManagementController::class, 'status'])
            ->name('profiles.status');
        Route::get('/profiles', [SeoManagementController::class, 'index'])
            ->name('profiles.index');
        Route::post('/profiles', [SeoManagementController::class, 'store'])
            ->name('profiles.store');
        Route::get('/profiles/{profile}', [SeoManagementController::class, 'show'])
            ->whereUuid('profile')
            ->name('profiles.show');
        Route::put('/profiles/{profile}', [SeoManagementController::class, 'update'])
            ->whereUuid('profile')
            ->name('profiles.update');
        Route::post('/profiles/{profile}/duplicate', [SeoManagementController::class, 'duplicate'])
            ->whereUuid('profile')
            ->name('profiles.duplicate');
        Route::patch('/profiles/{profile}/archive', [SeoManagementController::class, 'archive'])
            ->whereUuid('profile')
            ->name('profiles.archive');
        Route::get('/profiles/{profile}/preview', [SeoManagementController::class, 'preview'])
            ->whereUuid('profile')
            ->name('profiles.preview');
        Route::delete('/profiles/{profile}', [SeoManagementController::class, 'destroy'])
            ->whereUuid('profile')
            ->name('profiles.destroy');
    });
