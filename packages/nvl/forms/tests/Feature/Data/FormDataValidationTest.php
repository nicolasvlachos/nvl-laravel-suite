<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Models\Form;

test('form data validation passes with valid payload', function (): void {
    $payload = [
        'translations' => ['en' => ['name' => 'Contact Form']],
        'resolvement' => Resolvement::ENTRIES->value,
        'type' => FormType::LANDING_PAGE->value,
        'status' => FormStatus::DRAFT->value,
        'rateLimitPerHour' => 15,
    ];

    $validator = Validator::make(
        $payload,
        MutateFormPayload::rulesForCreate(),
        MutateFormPayload::messages(),
        MutateFormPayload::attributes(),
    );

    expect($validator->passes())->toBeTrue();
});

test('form data validation enforces handle uniqueness with route context', function (): void {
    $form = Form::factory()->create(['handle' => 'existing']);

    $payload = [
        'translations' => ['en' => ['name' => 'Updated Form']],
        'expectedRevision' => $form->revision,
        'handle' => 'existing',
        'resolvement' => Resolvement::ENTRIES->value,
        'type' => FormType::LANDING_PAGE->value,
        'status' => FormStatus::ACTIVE->value,
    ];

    $validator = Validator::make($payload, MutateFormPayload::rulesForUpdate($form->id));

    expect($validator->passes())->toBeTrue();

    Form::factory()->create(['handle' => 'taken']);
    $payload['handle'] = 'taken';

    $validator = Validator::make($payload, MutateFormPayload::rulesForUpdate($form->id));

    expect($validator->fails())->toBeTrue();
});

test('form data validation validates availability dates', function (): void {
    $payload = [
        'translations' => ['en' => ['name' => 'Timed Form']],
        'resolvement' => Resolvement::ENTRIES->value,
        'type' => FormType::LANDING_PAGE->value,
        'status' => FormStatus::PAUSED->value,
        'availableFrom' => now()->toDateString(),
        'availableUntil' => now()->subDay()->toDateString(),
    ];

    $validator = Validator::make($payload, MutateFormPayload::rulesForCreate());

    expect($validator->fails())->toBeTrue();
});
