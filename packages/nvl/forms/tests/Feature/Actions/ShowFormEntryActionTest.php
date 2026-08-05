<?php

declare(strict_types=1);

use Nvl\Forms\Actions\FormEntry\ShowFormEntryAction;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

test('show form entry action resolves by identifier and loads form relation', function (): void {
    $form = Form::factory()->create();
    $entry = FormEntry::factory()->for($form)->create();

    $resolved = app(ShowFormEntryAction::class)->execute($entry->id);

    expect($resolved->is($entry))->toBeTrue();
    expect($resolved->relationLoaded('form'))->toBeTrue();
    expect($resolved->form->id)->toBe($form->id);
});

test('show form entry action accepts existing model instances', function (): void {
    $entry = FormEntry::factory()->create();

    $resolved = app(ShowFormEntryAction::class)->execute($entry);

    expect($resolved->is($entry))->toBeTrue();
});
