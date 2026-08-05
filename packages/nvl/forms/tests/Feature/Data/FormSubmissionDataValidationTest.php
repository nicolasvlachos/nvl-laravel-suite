<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;

test('form submission data discards untrusted source metadata', function (): void {
    $payload = [
        'subject' => 'Contact',
        'email' => 'user@example.com',
        'submittedFrom' => 'https://example.com',
    ];

    $validator = Validator::make($payload, SubmitFormPayload::rules(), SubmitFormPayload::messages(), SubmitFormPayload::attributes());

    expect($validator->passes())->toBeTrue();

    $data = SubmitFormPayload::from($payload);
    expect($data->toArray())->not->toHaveKey('submittedFrom');
});

test('form submission data validation rejects invalid email addresses', function (): void {
    $payload = ['email' => 'not-email'];

    $validator = Validator::make($payload, SubmitFormPayload::rules());

    expect($validator->fails())->toBeTrue();
});

test('form submission data validation ensures submission data is array', function (): void {
    $payload = ['submissionData' => 'invalid'];

    $validator = Validator::make($payload, SubmitFormPayload::rules());

    expect($validator->fails())->toBeTrue();
});

test('form submission data validation accepts valid Bulgarian phone number', function (): void {
    $payload = ['phone' => '+359888123456'];

    $validator = Validator::make($payload, SubmitFormPayload::rules());

    expect($validator->passes())->toBeTrue();
});

test('form submission data validation accepts valid international phone number', function (): void {
    $payload = ['phone' => '+302101234567'];

    $validator = Validator::make($payload, SubmitFormPayload::rules());

    expect($validator->passes())->toBeTrue();
});

test('form submission data validation accepts short strings as phone number', function (): void {
    $payload = ['phone' => '12345'];

    $validator = Validator::make($payload, SubmitFormPayload::rules());

    expect($validator->passes())->toBeTrue();
});

test('form submission data validation accepts null phone', function (): void {
    $payload = ['phone' => null];

    $validator = Validator::make($payload, SubmitFormPayload::rules());

    expect($validator->passes())->toBeTrue();
});
