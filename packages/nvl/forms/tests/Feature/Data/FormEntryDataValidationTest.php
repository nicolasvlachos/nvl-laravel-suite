<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Forms\Data\Mutations\MutateFormEntryPayload;
use Nvl\Forms\Models\Form;

test('form entry data validation passes with valid payload', function (): void {
    $form = Form::factory()->create();

    $payload = [
        'formId' => $form->id,
        'subject' => 'Support request',
        'email' => 'user@example.com',
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'phone' => '+3591234567',
        'address' => '123 Example Street',
        'body' => 'Need assistance with the form.',
        'submissionData' => ['company' => 'Example Inc.'],
        'submittedFrom' => 'https://example.com/contact',
        'ipAddress' => '127.0.0.1',
        'userAgent' => 'Mozilla/5.0',
        'sessionId' => 'session-123',
        'isSpam' => false,
        'spamScore' => 5,
        'securityFlags' => ['akismet' => 'allow'],
    ];

    $validator = Validator::make($payload, MutateFormEntryPayload::rules(), MutateFormEntryPayload::messages(), MutateFormEntryPayload::attributes());

    expect($validator->passes())->toBeTrue();
});

test('form entry data validation requires form id and submission source', function (): void {
    $payload = [
        'email' => 'invalid-email',
    ];

    $validator = Validator::make($payload, MutateFormEntryPayload::rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->keys())->toContain('formId');
    expect($validator->errors()->keys())->toContain('submittedFrom');
});

test('form entry data validation enforces data types', function (): void {
    $form = Form::factory()->create();

    $payload = [
        'formId' => $form->id,
        'submittedFrom' => 'https://example.com/contact',
        'ipAddress' => 'not-an-ip',
        'submissionData' => 'invalid',
        'securityFlags' => 'invalid',
    ];

    $validator = Validator::make($payload, MutateFormEntryPayload::rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->keys())->toContain('ipAddress');
    expect($validator->errors()->keys())->toContain('submissionData');
    expect($validator->errors()->keys())->toContain('securityFlags');
});
