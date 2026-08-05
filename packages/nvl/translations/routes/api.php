<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Translations\Http\Controllers\Api\TranslationsApiController;
use Nvl\Translations\Support\TranslationConfiguration;

$routeMiddleware = array_values(array_filter(
    (array) config('translations.routes.middleware', ['api']),
    static fn (mixed $value): bool => is_string($value) && $value !== '',
));
$managementMiddleware = array_values(array_filter(
    (array) config('translations.routes.management_middleware', ['auth']),
    static fn (mixed $value): bool => is_string($value) && $value !== '',
));
$prefix = trim(TranslationConfiguration::string('translations.routes.prefix', 'api/v1'), '/');

Route::middleware($routeMiddleware)->prefix($prefix)->group(function () use ($managementMiddleware): void {
    Route::middleware($managementMiddleware)
        ->prefix('translations')
        ->name('nvl.translations.management.')
        ->group(function (): void {
            Route::get('/', [TranslationsApiController::class, 'index'])->name('index');
            Route::post('/import', [TranslationsApiController::class, 'import'])->name('import');
            Route::post('/export', [TranslationsApiController::class, 'export'])->name('export');
            Route::post('/scan', [TranslationsApiController::class, 'scan'])->name('scan');
            Route::match(['put', 'patch'], '/entries/{entry}', [TranslationsApiController::class, 'update'])
                ->whereUuid('entry')
                ->name('entries.update');
        });
});
