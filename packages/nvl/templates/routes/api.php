<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Templates\Http\Controllers\TemplateRenderController;
use Nvl\Templates\Http\Controllers\TemplatesController;
use Nvl\Templates\Support\TemplatesRouteConfiguration;

if ((bool) config('templates.routes.management.enabled', false)) {
    Route::prefix(TemplatesRouteConfiguration::path('management'))
        ->name(TemplatesRouteConfiguration::name('management'))
        ->middleware(TemplatesRouteConfiguration::middleware('management'))
        ->group(function (): void {
            Route::get('/', [TemplatesController::class, 'index'])->name('index');
            Route::post('/', [TemplatesController::class, 'store'])->name('store');
            Route::get('/{template}', [TemplatesController::class, 'show'])
                ->whereUuid('template')->name('show');
            Route::put('/{template}', [TemplatesController::class, 'update'])
                ->whereUuid('template')->name('update');
            Route::post('/{template}/versions', [TemplatesController::class, 'version'])
                ->whereUuid('template')->name('versions.store');
            Route::put('/versions/{version}', [TemplatesController::class, 'updateVersion'])
                ->whereUuid('version')->name('versions.update');
            Route::post('/versions/{version}/publish', [TemplatesController::class, 'publish'])
                ->whereUuid('version')->name('versions.publish');
            Route::put('/{template}/assignments', [TemplatesController::class, 'assign'])
                ->whereUuid('template')->name('assignments.store');
            Route::delete('/assignments/{assignment}', [TemplatesController::class, 'unassign'])
                ->whereUuid('assignment')->name('assignments.destroy');
        });
}

if ((bool) config('templates.routes.render.enabled', false)) {
    Route::prefix(TemplatesRouteConfiguration::path('render'))
        ->name(TemplatesRouteConfiguration::name('render'))
        ->middleware(TemplatesRouteConfiguration::middleware('render'))
        ->group(function (): void {
            Route::get('/renders', [TemplateRenderController::class, 'index'])
                ->name('history.index');
            Route::get('/renders/{render}', [TemplateRenderController::class, 'show'])
                ->whereUuid('render')
                ->name('history.show');
            Route::post('/{template}', [TemplateRenderController::class, 'render'])
                ->name('execute');
            Route::post('/{template}/queue', [TemplateRenderController::class, 'queue'])
                ->name('queue');
        });
}
