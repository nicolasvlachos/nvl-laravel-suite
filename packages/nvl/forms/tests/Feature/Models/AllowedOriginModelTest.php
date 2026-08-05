<?php

declare(strict_types=1);

use Nvl\Forms\Actions\AllowedOrigin\RecordAllowedOriginUsageAction;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Services\OriginMatchingService;

test('allowed origin services match host patterns and record usage', function (): void {
    $origin = AllowedOrigin::factory()->create(['origin' => '*.example.com']);
    $matcher = app(OriginMatchingService::class);

    expect($matcher->matches((string) $origin->origin, 'https://shop.example.com'))->toBeTrue()
        ->and($matcher->matches((string) $origin->origin, 'https://example.org'))->toBeFalse();

    app(RecordAllowedOriginUsageAction::class)->execute($origin);
    $origin->refresh();

    expect($origin->usage_count)->toBe(1)
        ->and($origin->last_used_at)->not->toBeNull();
});

test('origin matching service enforces host boundaries for wildcard rules', function (): void {
    $subdomainOrigin = AllowedOrigin::factory()->create(['origin' => '*.example.com']);
    $pathOrigin = AllowedOrigin::factory()->create(['origin' => 'example.com/*']);
    $matcher = app(OriginMatchingService::class);

    expect($matcher->matches((string) $subdomainOrigin->origin, 'good.example.com'))->toBeTrue()
        ->and($matcher->matches((string) $subdomainOrigin->origin, 'example.com'))->toBeFalse()
        ->and($matcher->matches((string) $subdomainOrigin->origin, 'notexample.com'))->toBeFalse()
        ->and($matcher->matches((string) $pathOrigin->origin, 'example.com'))->toBeTrue()
        ->and($matcher->matches((string) $pathOrigin->origin, 'example.com.evil'))->toBeFalse();
});
