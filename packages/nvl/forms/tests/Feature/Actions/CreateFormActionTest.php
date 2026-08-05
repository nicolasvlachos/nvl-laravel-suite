<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Nvl\Forms\Actions\Form\CreateFormAction;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Events\FormChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Tests\Stubs\TestFormsUser;

test('create form action persists a form with a generated handle', function (): void {
    Event::fake([FormChangedEvent::class]);
    $data = MutateFormPayload::from([
        'translations' => ['en' => ['name' => 'Marketing Landing Page']],
        'resolvement' => Resolvement::ENTRIES->value,
        'type' => FormType::LANDING_PAGE->value,
    ]);

    $actor = TestFormsUser::factory()->create();

    $form = app(CreateFormAction::class)->execute($data, $actor);

    expect($form->id)->not->toBeNull()
        ->and($form->handle)->toBe('marketing-landing-page')
        ->and($form->displayName())->toBe('Marketing Landing Page')
        ->and($form->fresh())->not->toBeNull();

    Event::assertDispatched(FormChangedEvent::class);
});

test('create form action honours a provided handle', function (): void {
    $data = MutateFormPayload::from([
        'translations' => ['en' => ['name' => 'Internal Feedback']],
        'handle' => 'internal-feedback',
        'resolvement' => Resolvement::ENTRIES->value,
        'type' => FormType::IFRAME->value,
    ]);

    $form = app(CreateFormAction::class)->execute($data);

    expect($form->handle)->toBe('internal-feedback')
        ->and($form->type->value)->toBe(FormType::IFRAME->value);

    $this->assertDatabaseHas(Form::query()->getModel()->getTable(), [
        'id' => $form->id,
        'handle' => 'internal-feedback',
    ]);
});
