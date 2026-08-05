<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;

test('form mutation data accepts normalized host-only allowed origins', function (): void {
    $payload = [
        'translations' => ['en' => ['name' => 'Public form']],
        'resolvement' => Resolvement::ENTRIES->value,
        'type' => FormType::LANDING_PAGE->value,
        'status' => FormStatus::ACTIVE->value,
        'allowedOrigins' => ['example.com', '*.example.com', 'example.com:8443', 'example.com/*', 'localhost:5173'],
    ];

    $validator = Validator::make($payload, MutateFormPayload::rulesForCreate());

    expect($validator->passes())->toBeTrue();
});

test('form mutation data rejects invalid allowed origin expressions', function (string $origin): void {
    $payload = [
        'translations' => ['en' => ['name' => 'Public form']],
        'resolvement' => Resolvement::ENTRIES->value,
        'type' => FormType::LANDING_PAGE->value,
        'status' => FormStatus::ACTIVE->value,
        'allowedOrigins' => [$origin],
    ];

    $validator = Validator::make($payload, MutateFormPayload::rulesForCreate());

    expect($validator->fails())->toBeTrue();
})->with([
    'scheme' => ['https://example.com'],
    'arbitrary path' => ['example.com/path'],
    'comma' => ['example.com,evil.com'],
    'backslash' => ['example.com\\evil'],
    'root wildcard' => ['*.com'],
    'invalid port' => ['example.com:0'],
]);
