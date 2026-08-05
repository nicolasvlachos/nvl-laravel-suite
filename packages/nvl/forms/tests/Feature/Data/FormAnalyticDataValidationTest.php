<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Forms\Data\FormAnalyticPayload;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;

test('form analytic data validation passes with valid payload', function (): void {
    $form = Form::factory()->create();

    $payload = [
        'formId' => $form->id,
        'eventType' => FormAnalyticEventType::VIEW->value,
        'origin' => 'https://example.com',
        'ipAddress' => '192.168.0.1',
        'userAgent' => 'Mozilla/5.0',
        'sessionId' => 'session-123',
        'metadata' => ['referrer' => 'newsletter'],
    ];

    $validator = Validator::make($payload, FormAnalyticPayload::rules(), FormAnalyticPayload::messages(), FormAnalyticPayload::attributes());

    expect($validator->passes())->toBeTrue();
});

test('form analytic data validation requires form id and event type', function (): void {
    $validator = Validator::make([], FormAnalyticPayload::rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->keys())->toContain('formId');
    expect($validator->errors()->keys())->toContain('eventType');
});

test('form analytic data validation rejects invalid event type', function (): void {
    $form = Form::factory()->create();

    $payload = [
        'formId' => $form->id,
        'eventType' => 'invalid',
    ];

    $validator = Validator::make($payload, FormAnalyticPayload::rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->keys())->toContain('eventType');
});
