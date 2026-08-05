<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;
use Nvl\Forms\Models\FormRateLimit;
use Nvl\Forms\Services\FormRateLimitService;
use Nvl\Forms\Services\FormRateLimitStatisticsService;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-02 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('rate limiting disabled paths remain read only', function (): void {
    $form = Form::factory()->create([
        'enable_rate_limiting' => false,
        'rate_limit_per_hour' => 0,
    ]);
    $service = app(FormRateLimitService::class);

    $attempt = $service->consumeSubmissionAttempt($form, '192.0.2.1');
    $status = $service->getRateLimitStatus($form, '192.0.2.1');

    expect($service->isRateLimited($form, '192.0.2.1'))->toBeFalse()
        ->and($attempt->allowed)->toBeTrue()
        ->and($attempt->rateLimit)->toBeNull()
        ->and($status)->toMatchArray([
            'enabled' => false,
            'remaining' => null,
            'is_blocked' => false,
            'retry_after' => 0,
            'violation_count' => 0,
        ])
        ->and(FormRateLimit::query()->count())->toBe(0);
});

test('rate limit status distinguishes absent active expired and blocked records', function (): void {
    $form = Form::factory()->create([
        'enable_rate_limiting' => true,
        'rate_limit_per_hour' => 3,
    ]);
    $service = app(FormRateLimitService::class);

    expect($service->isRateLimited($form, '192.0.2.2'))->toBeFalse()
        ->and($service->getRateLimitStatus($form, '192.0.2.2'))
        ->toMatchArray(['enabled' => true, 'remaining' => 3, 'is_blocked' => false]);

    $active = FormRateLimit::query()->create([
        'form_id' => $form->id,
        'ip_address' => '192.0.2.3',
        'submission_count' => 3,
        'window_start' => now()->subMinutes(10),
        'last_submission_at' => now()->subMinute(),
        'is_blocked' => false,
        'blocked_until' => null,
        'violation_count' => 2,
    ]);
    $expired = FormRateLimit::query()->create([
        'form_id' => $form->id,
        'ip_address' => '192.0.2.4',
        'submission_count' => 99,
        'window_start' => now()->subHours(2),
        'last_submission_at' => now()->subHours(2),
        'is_blocked' => false,
        'blocked_until' => null,
        'violation_count' => 1,
    ]);
    $indefinite = FormRateLimit::query()->create([
        'form_id' => $form->id,
        'ip_address' => '192.0.2.5',
        'submission_count' => 1,
        'window_start' => now(),
        'last_submission_at' => now(),
        'is_blocked' => true,
        'blocked_until' => null,
        'violation_count' => 4,
    ]);

    expect($service->isRateLimited($form, $active->ip_address))->toBeTrue()
        ->and($service->isRateLimited($form, $expired->ip_address))->toBeFalse()
        ->and($service->isRateLimited($form, $indefinite->ip_address))->toBeTrue()
        ->and($service->getRateLimitStatus($form, $active->ip_address))
        ->toMatchArray(['remaining' => 0, 'is_blocked' => false, 'violation_count' => 2])
        ->and($service->getRateLimitStatus($form, $expired->ip_address))
        ->toMatchArray(['remaining' => 3, 'is_blocked' => false, 'violation_count' => 1])
        ->and($service->getRateLimitStatus($form, $indefinite->ip_address)['retry_after'])
        ->toBeGreaterThanOrEqual(60);
});

test('submission attempts create reset block and deny atomically', function (): void {
    config()->set('forms.security.rate_limiting.block_duration_minutes', [1 => 5, 2 => 15]);
    $form = Form::factory()->create([
        'enable_rate_limiting' => true,
        'rate_limit_per_hour' => 2,
    ]);
    $service = app(FormRateLimitService::class);

    $first = $service->consumeSubmissionAttempt($form, '198.51.100.1');
    $second = $service->consumeSubmissionAttempt(
        $form,
        '198.51.100.1',
        'https://consumer.test',
        'Consumer/1.0',
        'session-1',
    );
    $third = $service->consumeSubmissionAttempt($form, '198.51.100.1');

    expect($first->allowed)->toBeTrue()
        ->and($first->submissionCount)->toBe(1)
        ->and($second->allowed)->toBeTrue()
        ->and($second->submissionCount)->toBe(2)
        ->and($second->rateLimit?->is_blocked)->toBeTrue()
        ->and($third->allowed)->toBeFalse()
        ->and($third->retryAfterSeconds)->toBeGreaterThan(0)
        ->and(FormAnalytic::query()->where('event_type', FormAnalyticEventType::RATE_LIMITED)->count())->toBe(1);

    $expired = FormRateLimit::query()->create([
        'form_id' => $form->id,
        'ip_address' => '198.51.100.2',
        'submission_count' => 8,
        'window_start' => now()->subHours(2),
        'last_submission_at' => now()->subHours(2),
        'is_blocked' => true,
        'blocked_until' => now()->subMinute(),
        'violation_count' => 1,
    ]);

    $reset = $service->consumeSubmissionAttempt($form, $expired->ip_address);

    expect($reset->allowed)->toBeTrue()
        ->and($reset->submissionCount)->toBe(1)
        ->and($expired->refresh()->is_blocked)->toBeFalse()
        ->and($expired->blocked_until)->toBeNull();
});

test('operators can explicitly block unblock whitelist and inspect statistics', function (): void {
    config()->set('forms.security.ip_blocking.cleanup_after_days', 7);
    $form = Form::factory()->create(['enable_rate_limiting' => true]);
    $service = app(FormRateLimitService::class);
    $statistics = app(FormRateLimitStatisticsService::class);
    $rateLimit = FormRateLimit::query()->create([
        'form_id' => $form->id,
        'ip_address' => '203.0.113.1',
        'submission_count' => 5,
        'window_start' => now()->subMinutes(5),
        'last_submission_at' => now(),
        'is_blocked' => false,
        'blocked_until' => null,
        'violation_count' => 2,
    ]);
    FormRateLimit::query()->create([
        'form_id' => $form->id,
        'ip_address' => '203.0.113.2',
        'submission_count' => 1,
        'window_start' => now()->subDays(8),
        'last_submission_at' => now()->subDays(8),
        'is_blocked' => false,
        'blocked_until' => null,
        'violation_count' => 1,
        'created_at' => now()->subDays(8),
    ]);

    $service->blockIpAddress($form, $rateLimit, 10);
    $status = $service->getRateLimitStatus($form, $rateLimit->ip_address);
    $global = $statistics->getGlobalStatistics();
    $formStatistics = $statistics->getFormStatistics($form);

    expect($status['is_blocked'])->toBeTrue()
        ->and($status['retry_after'])->toBeGreaterThan(0)
        ->and($global)->toMatchArray([
            'total_blocked_ips' => 1,
            'total_violations' => 4,
            'active_rate_limit_windows' => 1,
            'cleanup_needed' => 1,
        ])
        ->and($formStatistics['total_violations'])->toBe(4)
        ->and($formStatistics['blocked_ips'])->toBe(1)
        ->and($formStatistics['most_violations'])->toBe(3)
        ->and($formStatistics['top_violating_ips'])->toHaveKey('203.0.113.1');

    $service->unblockIpAddress($form, $rateLimit->ip_address);
    $service->unblockIpAddress($form, '203.0.113.99');
    expect($rateLimit->refresh()->is_blocked)->toBeFalse()
        ->and($service->cleanupExpiredRecords())->toBe(1);

    $service->whitelistIpAddress($form, $rateLimit->ip_address);
    expect($rateLimit->fresh())->toBeNull();
});
