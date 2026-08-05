<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Forms\Data\AllowedOriginPayload;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;

test('allowed origin data validation passes with valid payload', function (): void {
    $form = Form::factory()->create();

    $payload = [
        'formId' => $form->id,
        'origin' => 'example.com',
        'isActive' => true,
        'description' => 'Trusted domain',
        'corsSettings' => [
            'policy' => 'custom',
            'allowCredentials' => true,
            'maxAge' => 300,
            'allowedMethods' => ['GET', 'POST', 'OPTIONS'],
            'allowedHeaders' => ['Content-Type'],
        ],
        'usageCount' => 1,
        'lastUsedAt' => now()->toDateTimeString(),
    ];

    $validator = Validator::make(
        $payload,
        AllowedOriginPayload::rulesFor(null, $form->id),
        AllowedOriginPayload::messages(),
        AllowedOriginPayload::attributes(),
    );

    expect($validator->passes())->toBeTrue();
});

test('allowed origin data validation enforces uniqueness per form with route context', function (): void {
    $existing = AllowedOrigin::factory()->create(['origin' => 'duplicate.test']);

    $otherFormPayload = [
        'formId' => Form::factory()->create()->id,
        'origin' => $existing->origin,
    ];

    $otherFormValidator = Validator::make(
        $otherFormPayload,
        AllowedOriginPayload::rulesFor(null, $otherFormPayload['formId']),
    );

    expect($otherFormValidator->passes())->toBeTrue();

    $sameFormPayload = [
        'formId' => $existing->form_id,
        'origin' => $existing->origin,
    ];

    $sameFormValidator = Validator::make(
        $sameFormPayload,
        AllowedOriginPayload::rulesFor(null, $sameFormPayload['formId']),
    );

    expect($sameFormValidator->fails())->toBeTrue();

    $updatePayload = [
        'formId' => $existing->form_id,
        'origin' => $existing->origin,
    ];

    $updateValidator = Validator::make(
        $updatePayload,
        AllowedOriginPayload::rulesFor($existing->id, $updatePayload['formId']),
    );

    expect($updateValidator->passes())->toBeTrue();
});

test('allowed origin data validation accepts typed cors settings', function (): void {
    $form = Form::factory()->create();

    $payload = [
        'formId' => $form->id,
        'origin' => 'settings.test',
        'corsSettings' => ['maxAge' => 3600],
    ];

    $validator = Validator::make(
        $payload,
        AllowedOriginPayload::rulesFor(null, $form->id),
    );

    expect($validator->passes())->toBeTrue();
});

test('allowed origin data validation rejects unknown cors keys', function (): void {
    $form = Form::factory()->create();

    $validator = Validator::make([
        'formId' => $form->id,
        'origin' => 'settings.test',
        'corsSettings' => ['allowEverything' => true],
    ], AllowedOriginPayload::rulesFor(null, $form->id));

    expect($validator->fails())->toBeTrue();
});

test('allowed origin data rejects unsafe or non host-only expressions', function (string $origin): void {
    $form = Form::factory()->create();

    $payload = [
        'formId' => $form->id,
        'origin' => $origin,
    ];

    $validator = Validator::make(
        $payload,
        AllowedOriginPayload::rulesFor(null, $form->id),
    );

    expect($validator->fails())->toBeTrue();
})->with([
    'scheme' => ['https://example.com'],
    'path' => ['example.com/path'],
    'quote' => ['example.com"'],
    'semicolon' => ['example.com; frame-src *'],
    'empty label' => ['example..com'],
    'bad port' => ['example.com:99999'],
]);
