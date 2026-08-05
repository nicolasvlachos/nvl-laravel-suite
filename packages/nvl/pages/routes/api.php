<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Pages\Http\Controllers\PagesManagementController;
use Nvl\Pages\Http\Controllers\PublicNavigationController;
use Nvl\Pages\Http\Controllers\PublicPagesController;
use Nvl\Pages\Support\PagesRouteConfiguration;

if ((bool) config('pages.routes.management.enabled', false)) {
    Route::prefix(PagesRouteConfiguration::path('management'))
        ->name(PagesRouteConfiguration::name('management'))
        ->middleware(PagesRouteConfiguration::middleware('management'))
        ->group(function (): void {
            Route::get('/', [PagesManagementController::class, 'index'])->name('index');
            Route::post('/', [PagesManagementController::class, 'store'])->name('store');
            Route::get('/{page}', [PagesManagementController::class, 'show'])
                ->whereUuid('page')->name('show');
            Route::put('/{page}', [PagesManagementController::class, 'update'])
                ->whereUuid('page')->name('update');
            Route::put('/{page}/position', [PagesManagementController::class, 'move'])
                ->whereUuid('page')->name('move');
            Route::post('/{page}/restore', [PagesManagementController::class, 'restore'])
                ->whereUuid('page')->name('restore');
            Route::get('/preview/{path}', [PagesManagementController::class, 'preview'])
                ->where('path', '.+')->name('preview');
            Route::delete('/{page}', [PagesManagementController::class, 'destroy'])
                ->whereUuid('page')->name('destroy');
        });
}

if ((bool) config('pages.routes.public.enabled', false)) {
    Route::prefix(PagesRouteConfiguration::path('public'))
        ->name(PagesRouteConfiguration::name('public'))
        ->middleware(PagesRouteConfiguration::middleware('public'))
        ->group(function (): void {
            Route::get('/_navigation', PublicNavigationController::class)
                ->name('navigation');
            Route::get('/{path}', PublicPagesController::class)
                ->where('path', '.+')
                ->name('resolve');
        });
}
