<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Forms\Data\FormRateLimitPayload;
use Nvl\Forms\Models\Form;

test('form rate limit data validation passes with valid payload', function (): void {
    $form = Form::factory()->create();

    $payload = [
        'formId' => $form->id,
        'ipAddress' => '127.0.0.1',
        'windowStart' => now()->toDateTimeString(),
        'lastSubmissionAt' => now()->addMinute()->toDateTimeString(),
        'submissionCount' => 2,
        'isBlocked' => true,
        'blockedUntil' => now()->addMinutes(5)->toDateTimeString(),
        'violationCount' => 1,
    ];

    $validator = Validator::make($payload, FormRateLimitPayload::rules(), FormRateLimitPayload::messages(), FormRateLimitPayload::attributes());

    expect($validator->passes())->toBeTrue();
});

test('form rate limit data validation enforces chronological blocked until', function (): void {
    $form = Form::factory()->create();

    $payload = [
        'formId' => $form->id,
        'ipAddress' => '127.0.0.1',
        'windowStart' => now()->toDateTimeString(),
        'lastSubmissionAt' => now()->addMinute()->toDateTimeString(),
        'blockedUntil' => now()->subMinute()->toDateTimeString(),
    ];

    $validator = Validator::make($payload, FormRateLimitPayload::rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->keys())->toContain('blockedUntil');
});

test('form rate limit data validation enforces numeric counters and ip format', function (): void {
    $form = Form::factory()->create();

    $payload = [
        'formId' => $form->id,
        'ipAddress' => 'invalid-ip',
        'windowStart' => now()->toDateTimeString(),
        'lastSubmissionAt' => now()->addMinute()->toDateTimeString(),
        'submissionCount' => -1,
        'violationCount' => -5,
    ];

    $validator = Validator::make($payload, FormRateLimitPayload::rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->keys())->toContain('ipAddress');
    expect($validator->errors()->keys())->toContain('submissionCount');
    expect($validator->errors()->keys())->toContain('violationCount');
});
