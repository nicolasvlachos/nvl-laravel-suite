<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\RecordFormAnalyticAction;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;

test('record form analytic action creates an analytics event', function (): void {
    $form = Form::factory()->create();

    $analytic = app(RecordFormAnalyticAction::class)->execute(
        form: $form,
        eventType: FormAnalyticEventType::SUBMISSION,
        origin: 'https://example.com',
        ipAddress: '192.168.1.1',
        userAgent: 'Mozilla/5.0',
        sessionId: 'sess-abc',
    );

    expect($analytic)->toBeInstanceOf(FormAnalytic::class)
        ->and($analytic->form_id)->toBe($form->id)
        ->and($analytic->event_type)->toBe(FormAnalyticEventType::SUBMISSION)
        ->and($analytic->origin)->toBe('https://example.com')
        ->and($analytic->ip_address)->toBe('192.168.1.1');
});

test('record form analytic action accepts string event type', function (): void {
    $form = Form::factory()->create();

    $analytic = app(RecordFormAnalyticAction::class)->execute(
        form: $form,
        eventType: FormAnalyticEventType::VIEW->value,
    );

    expect($analytic->event_type)->toBe(FormAnalyticEventType::VIEW);
});

test('record form analytic action stores optional metadata', function (): void {
    $form = Form::factory()->create();

    $analytic = app(RecordFormAnalyticAction::class)->execute(
        form: $form,
        eventType: FormAnalyticEventType::ERROR,
        metadata: ['exception' => 'RuntimeException', 'trace' => 'stack'],
    );

    expect($analytic->metadata)->toBe(['exception' => 'RuntimeException', 'trace' => 'stack']);
});

test('record form analytic action accepts form id string', function (): void {
    $form = Form::factory()->create();

    $analytic = app(RecordFormAnalyticAction::class)->execute(
        form: $form->id,
        eventType: FormAnalyticEventType::SUBMISSION,
    );

    expect($analytic->form_id)->toBe($form->id);
});
