<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Nvl\Forms\Actions\FormEntry\CreateFormEntryAction;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;
use Nvl\Forms\Models\FormEntry;

test('create form entry action orchestrates dependencies for legitimate submission', function (): void {
    Event::fake([FormEntryChangedEvent::class]);
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'enable_rate_limiting' => false,
    ]);

    $data = FormEntryPayload::from([
        'formId' => $form->id,
        'subject' => 'Support enquiry',
        'email' => 'support@example.com',
        'submittedFrom' => 'landing.example.com',
    ]);

    $entry = app(CreateFormEntryAction::class)->execute(
        $data,
        '127.0.0.1',
        'Mozilla/5.0',
        'session-1234',
        null
    );

    expect($entry)->toBeInstanceOf(FormEntry::class)
        ->and($entry->form_id)->toBe($form->id);

    Event::assertDispatched(FormEntryChangedEvent::class);

    $form->refresh();
    expect($form->submissions_count)->toBe(1);
});

test('create form entry action records honeypot rejections as spam', function (): void {
    config(['forms.security.spam_protection.honeypot.field_names' => ['website']]);

    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'enable_rate_limiting' => false,
        'enable_honeypot' => true,
        'spam_count' => 0,
    ]);

    $data = FormEntryPayload::from([
        'formId' => $form->id,
        'submittedFrom' => 'landing.example.com',
        'submissionData' => ['website' => 'https://spam.example'],
    ]);

    expect(fn () => app(CreateFormEntryAction::class)->execute(
        $data,
        '127.0.0.1',
        'curl/8.0',
        'session-1234',
        null
    ))->toThrow(Exception::class, trans('forms::forms/shared.messages.error.bot_detected'));

    expect($form->fresh()->spam_count)->toBe(1);

    $analytic = FormAnalytic::firstOrFail();
    expect($analytic->event_type)->toBe(FormAnalyticEventType::SPAM_BLOCKED)
        ->and($analytic->metadata)->toMatchArray([
            'reason' => 'honeypot',
            'score' => 100,
            'flags' => ['honeypot' => true],
            'channel' => 'entries',
        ]);
});
