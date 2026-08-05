<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Content\Http\Controllers\ContentBlocksController;
use Nvl\Content\Http\Controllers\ContentCompositionController;
use Nvl\Content\Support\ContentRouteConfiguration;

if ((bool) config('content.routes.management.enabled', false)) {
    Route::prefix(ContentRouteConfiguration::path('management'))
        ->name(ContentRouteConfiguration::name('management'))
        ->middleware(ContentRouteConfiguration::middleware('management'))
        ->group(function (): void {
            Route::get('/presets', [ContentBlocksController::class, 'presets'])
                ->name('presets.index');
            Route::get('/definitions', [ContentBlocksController::class, 'definitions'])
                ->name('definitions.index');
            Route::get(
                '/owners/{ownerType}/{ownerId}/groups',
                [ContentBlocksController::class, 'groups'],
            )
                ->where('ownerType', '[a-z][a-z0-9_.-]{0,99}')
                ->where('ownerId', '[A-Za-z0-9_.:-]{1,191}')
                ->name('groups.index');
            Route::get(
                '/owners/{ownerType}/{ownerId}/groups/{group}/placements',
                [ContentBlocksController::class, 'placements'],
            )
                ->where('ownerType', '[a-z][a-z0-9_.-]{0,99}')
                ->where('ownerId', '[A-Za-z0-9_.:-]{1,191}')
                ->where('group', '[a-z][a-z0-9_.-]{0,99}')
                ->name('placements.index');
            Route::get(
                '/owners/{ownerType}/{ownerId}/groups/{group}/editor',
                [ContentBlocksController::class, 'editor'],
            )
                ->where('ownerType', '[a-z][a-z0-9_.-]{0,99}')
                ->where('ownerId', '[A-Za-z0-9_.:-]{1,191}')
                ->where('group', '[a-z][a-z0-9_.-]{0,99}')
                ->name('editor.show');
            Route::get(
                '/owners/{ownerType}/{ownerId}/groups/{group}/preview',
                [ContentCompositionController::class, 'preview'],
            )
                ->where('ownerType', '[a-z][a-z0-9_.-]{0,99}')
                ->where('ownerId', '[A-Za-z0-9_.:-]{1,191}')
                ->where('group', '[a-z][a-z0-9_.-]{0,99}')
                ->name('compositions.preview');
            Route::get('/blocks', [ContentBlocksController::class, 'index'])->name('blocks.index');
            Route::post('/blocks', [ContentBlocksController::class, 'store'])->name('blocks.store');
            Route::get('/blocks/{block}', [ContentBlocksController::class, 'show'])
                ->whereUuid('block')->name('blocks.show');
            Route::match(['put', 'patch'], '/blocks/{block}', [ContentBlocksController::class, 'update'])
                ->whereUuid('block')->name('blocks.update');
            Route::post('/blocks/{block}/publish', [ContentBlocksController::class, 'publish'])
                ->whereUuid('block')->name('blocks.publish');
            Route::post('/blocks/{block}/archive', [ContentBlocksController::class, 'archive'])
                ->whereUuid('block')->name('blocks.archive');
            Route::delete('/blocks/{block}', [ContentBlocksController::class, 'destroy'])
                ->whereUuid('block')->name('blocks.destroy');
            Route::post('/blocks/{block}/restore', [ContentBlocksController::class, 'restore'])
                ->whereUuid('block')->name('blocks.restore');
            Route::post(
                '/owners/{ownerType}/{ownerId}/groups/{group}/blocks/{block}/placements',
                [ContentBlocksController::class, 'place'],
            )
                ->where('ownerType', '[a-z][a-z0-9_.-]{0,99}')
                ->where('ownerId', '[A-Za-z0-9_.:-]{1,191}')
                ->where('group', '[a-z][a-z0-9_.-]{0,99}')
                ->whereUuid('block')
                ->name('placements.store');
            Route::match(
                ['put', 'patch'],
                '/placements/{placement}',
                [ContentBlocksController::class, 'updatePlacement'],
            )
                ->whereUuid('placement')->name('placements.update');
            Route::delete(
                '/placements/{placement}',
                [ContentBlocksController::class, 'destroyPlacement'],
            )->whereUuid('placement')->name('placements.destroy');
        });
}

if ((bool) config('content.routes.public.enabled', false)) {
    Route::prefix(ContentRouteConfiguration::path('public'))
        ->name(ContentRouteConfiguration::name('public'))
        ->middleware(ContentRouteConfiguration::middleware('public'))
        ->group(function (): void {
            Route::get(
                '/owners/{ownerType}/{ownerId}/groups/{group}/composition',
                [ContentCompositionController::class, 'show'],
            )
                ->where('ownerType', '[a-z][a-z0-9_.-]{0,99}')
                ->where('ownerId', '[A-Za-z0-9_.:-]{1,191}')
                ->where('group', '[a-z][a-z0-9_.-]{0,99}')
                ->name('compositions.show');
        });
}
