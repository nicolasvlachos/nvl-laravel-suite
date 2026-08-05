<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Nvl\Forms\Providers\FormsServiceProvider;

test('distributed route defaults are disabled independently', function (): void {
    /** @var array<string, mixed> $defaults */
    $defaults = require __DIR__.'/../../config/forms.php';

    expect(data_get($defaults, 'routes.management.enabled'))->toBeFalse()
        ->and(data_get($defaults, 'routes.public.enabled'))->toBeFalse()
        ->and(data_get($defaults, 'routes.prefix'))->toBe('api/v1');
});

test('consumer configuration wins while omitted nested package defaults remain available', function (): void {
    config()->set('forms', [
        'routes' => [
            'prefix' => 'consumer/forms',
        ],
        'security' => [
            'rate_limit' => [
                'max_attempts' => 7,
            ],
        ],
    ]);

    (new FormsServiceProvider(app()))->register();

    expect(config('forms.routes.prefix'))->toBe('consumer/forms')
        ->and(config('forms.routes.management.enabled'))->toBeFalse()
        ->and(config('forms.routes.public.enabled'))->toBeFalse()
        ->and(config('forms.security.rate_limit.max_attempts'))->toBe(7)
        ->and(config('forms.security.rate_limit.decay_minutes'))->toBe(1);
});

test('the public route limiter uses the documented consumer configuration', function (): void {
    config()->set('forms.security.rate_limit.max_attempts', 7);
    config()->set('forms.security.rate_limit.decay_minutes', 2);

    $limiter = RateLimiter::limiter('forms-public');

    expect($limiter)->toBeCallable();

    $limit = $limiter(Request::create('/forms', 'POST'));

    expect($limit->maxAttempts)->toBe(7)
        ->and($limit->decaySeconds)->toBe(120);
});
