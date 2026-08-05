<?php

declare(strict_types=1);

use Nvl\Forms\Actions\FormEntry\CheckFormRateLimitAction;
use Nvl\Forms\Contracts\FormRateLimiter;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormRateLimit;
use Nvl\Forms\Results\FormRateLimitAttemptResult;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

test('check form rate limit action allows submissions when rate limiting disabled', function (): void {
    $form = Form::factory()->create(['enable_rate_limiting' => false]);

    app(CheckFormRateLimitAction::class)->execute($form, '127.0.0.1');

    expect(true)->toBeTrue();
});

test('check form rate limit action throws when ip is blocked', function (): void {
    $form = Form::factory()->create([
        'enable_rate_limiting' => true,
        'rate_limit_per_hour' => 1,
    ]);

    $rateLimitService = Mockery::mock(FormRateLimiter::class);
    $rateLimit = FormRateLimit::create([
        'form_id' => $form->id,
        'ip_address' => '127.0.0.1',
        'submission_count' => 1,
        'window_start' => now(),
        'last_submission_at' => now(),
        'is_blocked' => true,
        'blocked_until' => now()->addMinutes(15),
        'violation_count' => 1,
    ]);

    $rateLimitService->shouldReceive('consumeSubmissionAttempt')
        ->once()
        ->with($form, '127.0.0.1', null, null, null)
        ->andReturn(FormRateLimitAttemptResult::denied($rateLimit, 900));

    $this->expectException(TooManyRequestsHttpException::class);
    $this->expectExceptionMessage(trans('forms::forms/shared.messages.error.rate_limit_exceeded'));

    (new CheckFormRateLimitAction($rateLimitService))->execute($form, '127.0.0.1');
});
