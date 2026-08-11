<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Nvl\Forms\Actions\Form\DuplicateFormAction;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Events\FormChangedEvent;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;

test('duplicate form action clones the form with reset counters', function (): void {
    Event::fake([FormChangedEvent::class]);
    $form = Form::factory()->create([
        'name' => 'Signup Form',
        'description' => 'Collect account details.',
        'translations' => [
            'en' => [
                'name' => 'Signup Form',
                'description' => 'Collect account details.',
                'submit_button_label' => 'Create account',
                'content' => ['fields' => [['type' => 'email']]],
            ],
        ],
        'handle' => 'signup-form',
        'submissions_count' => 3,
        'views_count' => 15,
        'spam_count' => 2,
    ]);

    $origin = AllowedOrigin::factory()->for($form)->create([
        'origin' => 'https://original.example.com',
        'usage_count' => 4,
    ]);
    $form->translations()->where('locale', 'en')->update([
        'submit_button_label' => 'Create account',
    ]);

    $duplicate = app(DuplicateFormAction::class)->execute($form, 'Signup Form Copy');
    $localizedContent = $duplicate->localizedContent();

    expect($duplicate->displayName())->toBe('Signup Form Copy')
        ->and($duplicate->displayDescription())->toBe('Collect account details.')
        ->and($localizedContent)->toHaveCount(4)
        ->and($localizedContent['name'])->toBe('Signup Form')
        ->and($localizedContent['description'])->toBe('Collect account details.')
        ->and($localizedContent['submit_button_label'])->toBe('Create account')
        ->and($localizedContent['content'])->toBe(['fields' => [['type' => 'email']]])
        ->and($duplicate->translations->sole()->submit_button_label)->toBe('Create account')
        ->and($duplicate->handle)->not->toBe($form->handle)
        ->and($duplicate->status->value)->toBe('draft')
        ->and($duplicate->submissions_count)->toBe(0)
        ->and($duplicate->views_count)->toBe(0)
        ->and($duplicate->spam_count)->toBe(0);

    Event::assertDispatched(FormChangedEvent::class);

    $this->assertDatabaseHas(FormsTables::ALLOWED_ORIGINS, [
        'form_id' => $duplicate->id,
        'origin' => $origin->origin,
        'usage_count' => 0,
    ]);
});
