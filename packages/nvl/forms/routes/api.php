<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\FrameGuard;
use Illuminate\Support\Facades\Route;
use Nvl\Forms\Http\Controllers\Api\FormRenderApiController;
use Nvl\Forms\Http\Controllers\Api\FormsApiController;
use Nvl\Forms\Models\Form;

$managementMiddleware = array_values(array_filter(
    (array) config('forms.routes.management.middleware', ['auth']),
    static fn (mixed $value): bool => is_string($value) && $value !== '',
));
$publicMiddleware = array_values(array_filter(
    (array) config('forms.routes.public.middleware', ['throttle:forms-public']),
    static fn (mixed $value): bool => is_string($value) && $value !== '',
));

if ((bool) config('forms.routes.management.enabled', false)) {
    Route::middleware($managementMiddleware)->group(function () {
        // Forms search/autocomplete endpoints
        Route::prefix('forms')->name('nvl.forms.management.')->group(function () {
            Route::get('/', [FormsApiController::class, 'index'])
                ->middleware('can:viewAny,'.Form::class)
                ->name('index');
            Route::post('/', [FormsApiController::class, 'store'])
                ->middleware('can:create,'.Form::class)
                ->name('store');
            Route::get('/suggestions', [FormsApiController::class, 'suggestions'])
                ->middleware('can:viewAny,'.Form::class)
                ->name('suggestions');
            Route::get('/search', [FormsApiController::class, 'search'])
                ->middleware('can:viewAny,'.Form::class)
                ->name('search');
            Route::get('/select', [FormsApiController::class, 'select'])
                ->middleware('can:viewAny,'.Form::class)
                ->name('select');
            Route::get('/{form}', [FormsApiController::class, 'show'])
                ->whereUuid('form')
                ->middleware('can:view,form')
                ->name('show');
            Route::match(['put', 'patch'], '/{form}', [FormsApiController::class, 'update'])
                ->whereUuid('form')
                ->middleware('can:update,form')
                ->name('update');
            Route::delete('/{form}', [FormsApiController::class, 'destroy'])
                ->whereUuid('form')
                ->middleware('can:delete,form')
                ->name('destroy');
            Route::post('/{form}/duplicate', [FormsApiController::class, 'duplicate'])
                ->whereUuid('form')
                ->middleware('can:duplicate,form')
                ->name('duplicate');
        });
    });
}

// Public API routes for iframe rendering and form submission
if ((bool) config('forms.routes.public.enabled', false)) {
    Route::prefix('forms')
        ->name('nvl.forms.public.')
        ->middleware(array_values(array_unique([...$publicMiddleware, 'forms-locale'])))
        ->group(function () {
            // Form rendering endpoints (with host validation middleware)
            Route::middleware(['validate-form-host'])
                ->withoutMiddleware([FrameGuard::class])
                ->group(function () {
                    Route::middleware(['form-available'])->group(function () {
                        Route::get('/{form}/render', [FormRenderApiController::class, 'show'])
                            ->name('render');
                        Route::post('/{form}/submit', [FormRenderApiController::class, 'submit'])
                            ->name('submit');
                        Route::options('/{form}/submit', [FormRenderApiController::class, 'options'])
                            ->name('options');
                        Route::get('/{form}/schema', [FormRenderApiController::class, 'schema'])
                            ->name('schema');
                    });
                });
        });
}
