<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Nvl\Forms\Actions\Form\UpdateFormAction;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Events\FormChangedEvent;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;

test('update form action updates persisted attributes', function (): void {
    Event::fake([FormChangedEvent::class]);
    $form = Form::factory()->create([
        'name' => 'Legacy Form',
        'handle' => 'legacy-form',
        'type' => FormType::LANDING_PAGE,
    ]);

    $data = MutateFormPayload::from([
        'translations' => ['en' => ['name' => 'Updated Form']],
        'handle' => 'updated-form',
        'type' => FormType::IFRAME->value,
        'resolvement' => Resolvement::ENTRIES->value,
        'expectedRevision' => $form->revision,
    ]);

    $updated = app(UpdateFormAction::class)->execute($form, $data);

    expect($updated->displayName())->toBe('Updated Form')
        ->and($updated->handle)->toBe('updated-form')
        ->and($updated->type->value)->toBe(FormType::IFRAME->value)
        ->and($updated->revision)->toBe(2);

    Event::assertDispatched(FormChangedEvent::class);
});

test('update form action preserves omitted attributes and applies explicit null clears', function (): void {
    $form = Form::factory()->create([
        'name' => 'Existing Form',
        'description' => 'Clear me',
        'handle' => 'existing-form',
        'status' => 'active',
        'type' => FormType::IFRAME,
        'resolvement' => Resolvement::CUSTOM,
        'restrict_public_access' => true,
        'allow_multiple_registrations' => false,
        'enable_honeypot' => false,
        'rate_limit_per_hour' => 25,
    ]);

    $updated = app(UpdateFormAction::class)->execute(
        $form,
        MutateFormPayload::from([
            'translations' => ['en' => ['name' => 'Renamed Form', 'description' => null]],
            'expectedRevision' => $form->revision,
        ]),
    );

    expect($updated->displayName())->toBe('Renamed Form')
        ->and($updated->displayDescription())->toBeNull()
        ->and($updated->handle)->toBe('existing-form')
        ->and($updated->status->value)->toBe('active')
        ->and($updated->type)->toBe(FormType::IFRAME)
        ->and($updated->resolvement)->toBe(Resolvement::CUSTOM)
        ->and($updated->restrict_public_access)->toBeTrue()
        ->and($updated->allow_multiple_registrations)->toBeFalse()
        ->and($updated->enable_honeypot)->toBeFalse()
        ->and($updated->rate_limit_per_hour)->toBe(25);
});

test('update form action rejects duplicate handles', function (): void {
    Form::factory()->create(['handle' => 'existing-handle']);
    $form = Form::factory()->create(['handle' => 'original-handle']);

    $data = MutateFormPayload::from([
        'handle' => 'existing-handle',
        'resolvement' => Resolvement::ENTRIES->value,
        'type' => FormType::LANDING_PAGE->value,
        'expectedRevision' => $form->revision,
    ]);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage(trans('forms::forms/messages.error.handle_exists', ['handle' => 'existing-handle']));

    app(UpdateFormAction::class)->execute($form, $data);
});

test('update form action syncs allowed origins by deactivating removed and creating new', function (): void {
    $form = Form::factory()->create([
        'type' => FormType::IFRAME,
        'restrict_public_access' => true,
    ]);

    $existing = AllowedOrigin::factory()->create([
        'form_id' => $form->id,
        'origin' => 'old.example.com',
        'is_active' => true,
    ]);

    $data = MutateFormPayload::from([
        'translations' => ['en' => ['name' => $form->displayName()]],
        'handle' => $form->handle,
        'type' => FormType::IFRAME->value,
        'resolvement' => Resolvement::ENTRIES->value,
        'restrictPublicAccess' => true,
        'allowedOrigins' => ['example.com', '*.allowed.com'],
        'expectedRevision' => $form->revision,
    ]);

    $updated = app(UpdateFormAction::class)->execute($form->fresh(), $data);
    $updated->loadMissing('allowedOrigins');

    $active = $updated->allowedOrigins->where('is_active', true)->pluck('origin')->all();
    sort($active);
    expect($active)->toEqual(['*.allowed.com', 'example.com']);

    $existing->refresh();
    expect($existing->is_active)->toBeFalse();
});
