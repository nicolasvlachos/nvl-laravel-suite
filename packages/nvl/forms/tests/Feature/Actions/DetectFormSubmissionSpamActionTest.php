<?php

declare(strict_types=1);

use Nvl\Forms\Actions\FormEntry\DetectFormSubmissionSpamAction;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormRateLimit;

test('detect form submission spam action flags suspicious content', function (): void {
    $form = Form::factory()->create();

    $data = FormEntryPayload::from([
        'subject' => 'WIN BIG BITCOIN NOW',
        'email' => 'user@tempmail.com',
        'submissionData' => ['body' => 'Visit http://spam.example.com for VIAGRA deals'],
    ]);

    $result = app(DetectFormSubmissionSpamAction::class)->execute($form, $data, '127.0.0.1', 'curl/8.0');

    expect($result['is_spam'])->toBeTrue()
        ->and($result['score'])->toBeGreaterThanOrEqual(50)
        ->and($result['flags'])->toHaveKey('suspicious_user_agent');
});

test('detect form submission spam action detects rapid submissions from rate limit records', function (): void {
    $form = Form::factory()->create();

    // Seed a FormRateLimit record with 6 submissions in the current window
    FormRateLimit::create([
        'form_id' => $form->id,
        'ip_address' => '192.168.1.1',
        'submission_count' => 6,
        'window_start' => now(),
        'last_submission_at' => now(),
        'is_blocked' => false,
        'blocked_until' => null,
        'violation_count' => 0,
    ]);

    $data = FormEntryPayload::from([
        'subject' => 'Hello',
    ]);

    $result = app(DetectFormSubmissionSpamAction::class)->execute($form, $data, '192.168.1.1', 'Mozilla/5.0');

    expect($result['flags'])->toHaveKey('rapid_submissions')
        ->and($result['flags']['rapid_submissions'])->toBe(6);
});
