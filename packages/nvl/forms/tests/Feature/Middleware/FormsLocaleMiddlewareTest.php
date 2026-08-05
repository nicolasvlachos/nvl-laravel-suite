<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    app()->setLocale('en');
    config(['app.supported_locales' => ['en', 'bg']]);
    config(['app.fallback_locale' => 'bg']);

    if (! Route::has('testing.forms.locale')) {
        Route::middleware('forms-locale')
            ->get('/testing/forms/locale', fn () => response()->json([
                'locale' => app()->getLocale(),
            ]))
            ->name('testing.forms.locale');
    }
});

test('forms locale middleware normalizes valid language parameter', function (): void {
    $response = $this->getJson('/testing/forms/locale?lang=bg-BG');

    $response->assertOk()->assertJson([
        'locale' => 'bg',
    ]);
});

test('forms locale middleware falls back for unsupported language input', function (): void {
    $response = $this->getJson('/testing/forms/locale?lang=invalid_language');

    $response->assertOk()->assertJson([
        'locale' => 'bg',
    ]);
});
