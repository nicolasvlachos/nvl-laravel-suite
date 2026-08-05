<?php

declare(strict_types=1);

use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormRateLimit;

test('form rate limit model resets window and tracks blocking', function (): void {
    $form = Form::factory()->create();
    $blocked = FormRateLimit::create([
        'form_id' => $form->id,
        'ip_address' => '127.0.0.1',
        'submission_count' => 1,
        'window_start' => now()->subMinutes(30),
        'last_submission_at' => now()->subMinutes(5),
        'is_blocked' => true,
        'blocked_until' => now()->addHour(),
        'violation_count' => 2,
    ]);

    $expiredBlock = FormRateLimit::create([
        'form_id' => $form->id,
        'ip_address' => '127.0.0.2',
        'submission_count' => 1,
        'window_start' => now()->subHours(2),
        'last_submission_at' => now()->subHours(2),
        'is_blocked' => true,
        'blocked_until' => now()->subMinute(),
        'violation_count' => 1,
    ]);

    $unblocked = FormRateLimit::create([
        'form_id' => $form->id,
        'ip_address' => '127.0.0.3',
        'submission_count' => 1,
        'window_start' => now()->subMinutes(10),
        'last_submission_at' => now()->subMinutes(2),
        'is_blocked' => false,
        'blocked_until' => null,
        'violation_count' => 0,
    ]);

    $blockedIds = FormRateLimit::query()->blocked()->pluck('id')->all();
    $activeWindowIds = FormRateLimit::query()->activeWindow()->pluck('id')->all();

    expect($blockedIds)->toContain($blocked->id)
        ->and($blockedIds)->not->toContain($expiredBlock->id)
        ->and($blockedIds)->not->toContain($unblocked->id)
        ->and($activeWindowIds)->toContain($blocked->id)
        ->and($activeWindowIds)->toContain($unblocked->id)
        ->and($activeWindowIds)->not->toContain($expiredBlock->id);
});
