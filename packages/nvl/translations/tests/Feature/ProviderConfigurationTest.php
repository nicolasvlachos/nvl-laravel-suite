<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Providers\TranslationsServiceProvider;

test('consumer configuration wins while omitted nested package defaults remain available', function (): void {
    config()->set('translations', [
        'routes' => [
            'prefix' => 'consumer/translations',
        ],
    ]);

    (new TranslationsServiceProvider(app()))->register();

    expect(config('translations.routes.prefix'))->toBe('consumer/translations')
        ->and(config('translations.routes.enabled'))->toBeFalse()
        ->and(config('translations.routes.management_middleware'))->toBe(['auth'])
        ->and(config('translations.paths.app'))->toBe(lang_path())
        ->and(config('translations.export_targets.source'))->toBe([])
        ->and(config('translations.import.conflict_strategy'))->toBe('fail')
        ->and(config('translations.scan_allowlist'))->toBe(['errors.*']);
});

test('package validation translations load for supported locales', function (): void {
    expect(trans('translations::translations/validation.attributes.value', [], 'en'))
        ->toBe('translation value')
        ->and(trans('translations::translations/validation.attributes.value', [], 'bg'))
        ->toBe('стойност на превода');
});

test('management routes remain disabled by default', function (): void {
    $this->getJson('/api/v1/translations')->assertNotFound();
    $this->postJson('/api/v1/translations/import')->assertNotFound();
    $this->postJson('/api/v1/translations/export')->assertNotFound();
    $this->postJson('/api/v1/translations/scan')->assertNotFound();
});

test('the package does not infer an application authorization policy', function (): void {
    expect(config('translations.authorization.ability'))->toBeNull()
        ->and(fn () => app(TranslationsAuthorization::class)->authorize(
            TranslationsAbility::ListEntries,
        ))
        ->toThrow(AuthorizationException::class);
});
