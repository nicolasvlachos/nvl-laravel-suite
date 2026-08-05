<?php

declare(strict_types=1);

use App\Http\Controllers\ApiProfileController;
use App\Http\Controllers\AuthConsumerSessionController;
use App\Http\Controllers\AuthManagement\AccessCatalogController;
use App\Http\Controllers\AuthManagement\UserManagementController;
use App\Http\Controllers\CsrfTokenController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Nvl\Auth\Http\Middleware\ValidateManagedAccessToken;

Route::get('auth-consumer/csrf-token', CsrfTokenController::class)
    ->middleware('web')
    ->name('consumer.auth.csrf_token');

Route::post('auth-consumer/session', AuthConsumerSessionController::class)
    ->middleware(['web', 'throttle:api'])
    ->name('consumer.auth.session.store');

Route::get('api/v1/auth-consumer/profile', ApiProfileController::class)
    ->middleware([
        'auth:sanctum',
        'throttle:api',
        ValidateManagedAccessToken::class,
        CheckAbilities::class.':profile:read',
    ])
    ->name('consumer.auth.profile.show');

Route::prefix('api/v1/auth/management')
    ->name('consumer.auth.management.')
    ->middleware([
        'web',
        'auth',
        'can:manage-authentication',
        'throttle:nvl-auth-management',
    ])
    ->group(function (): void {
        Route::get('users', [UserManagementController::class, 'index'])
            ->name('users.index');
        Route::post('users', [UserManagementController::class, 'store'])
            ->name('users.store');
        Route::get('users/{user}', [UserManagementController::class, 'show'])
            ->name('users.show');
        Route::match(['put', 'patch'], 'users/{user}', [UserManagementController::class, 'update'])
            ->name('users.update');
        Route::delete('users/{user}', [UserManagementController::class, 'destroy'])
            ->name('users.destroy');
        Route::put('users/{user}/access', [UserManagementController::class, 'access'])
            ->name('users.access');
        Route::get('roles', [AccessCatalogController::class, 'roles'])
            ->name('roles.index');
        Route::get('permissions', [AccessCatalogController::class, 'permissions'])
            ->name('permissions.index');
        Route::post('access-catalog/synchronize', [AccessCatalogController::class, 'synchronize'])
            ->name('access_catalog.synchronize');
    });
