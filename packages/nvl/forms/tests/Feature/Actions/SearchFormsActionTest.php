<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\SearchFormsAction;
use Nvl\Forms\Models\Form;

test('search forms action filters by submissions and limit', function (): void {
    Form::factory()->create(['name' => 'Active Form', 'submissions_count' => 5]);
    Form::factory()->create(['name' => 'Inactive Form', 'submissions_count' => 0]);

    $result = app(SearchFormsAction::class)->execute([
        'has_submissions' => true,
        'limit' => 1,
    ]);

    expect($result->forms)->toHaveCount(1)
        ->and($result->forms->first()->displayName())->toBe('Active Form')
        ->and($result->total)->toBe(1);
});

test('search forms action can eager load relations safely', function (): void {
    $form = Form::factory()->create();
    $form->entries()->create([
        'subject' => 'Hello',
        'submitted_from' => 'example.com',
    ]);

    $result = app(SearchFormsAction::class)->execute([
        'with' => ['entries'],
        'limit' => 10,
    ]);

    expect($result->forms->first()->relationLoaded('entries'))->toBeTrue()
        ->and($result->total)->toBe(1);
});
