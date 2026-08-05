<?php

declare(strict_types=1);

use Nvl\Forms\Services\FormsDoctor;

test('forms doctor reports a healthy standalone installation', function (): void {
    $checks = collect(app(FormsDoctor::class)->inspect());

    expect($checks)->not->toBeEmpty()
        ->and($checks->every(
            static fn (object $check): bool => $check->passed === true,
        ))->toBeTrue();

    $this->artisan('nvl:forms:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ])->assertSuccessful();
});

test('forms doctor rejects enabled management routes without a registered gate', function (): void {
    config(['forms.authorization.gate' => 'missing-forms-gate']);

    $check = collect(app(FormsDoctor::class)->inspect())
        ->firstWhere('key', 'authorization.management');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse();
});

test('forms doctor rejects enabled public routes without throttling', function (): void {
    config(['forms.routes.public.middleware' => ['api']]);

    $check = collect(app(FormsDoctor::class)->inspect())
        ->firstWhere('key', 'routes.public.throttle');

    expect($check)->not->toBeNull()
        ->and($check->passed)->toBeFalse();
});
