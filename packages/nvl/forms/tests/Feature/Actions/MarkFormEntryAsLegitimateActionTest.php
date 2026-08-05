<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Nvl\Forms\Actions\FormEntry\MarkFormEntryAsLegitimateAction;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

test('mark form entry as legitimate action clears spam flag', function (): void {
    Event::fake([FormEntryChangedEvent::class]);
    $form = Form::factory()->create(['spam_count' => 1]);
    $entry = FormEntry::factory()->for($form)->create(['is_spam' => true]);

    $result = app(MarkFormEntryAsLegitimateAction::class)->execute($entry);

    expect($result->is_spam)->toBeFalse()
        ->and($result->getSecurityFlag('marked_legitimate_at'))->not->toBeNull();

    Event::assertDispatched(FormEntryChangedEvent::class);
});

test('mark form entry as legitimate action decrements spam count when was spam', function (): void {
    $form = Form::factory()->create(['spam_count' => 3]);
    $entry = FormEntry::factory()->for($form)->create(['is_spam' => true]);

    app(MarkFormEntryAsLegitimateAction::class)->execute($entry);

    expect($form->fresh()->spam_count)->toBe(2);
});

test('mark form entry as legitimate action does not decrement when was not spam', function (): void {
    $form = Form::factory()->create(['spam_count' => 1]);
    $entry = FormEntry::factory()->for($form)->create(['is_spam' => false]);

    app(MarkFormEntryAsLegitimateAction::class)->execute($entry);

    expect($form->fresh()->spam_count)->toBe(1);
});

test('mark form entry as legitimate action does not go below zero', function (): void {
    $form = Form::factory()->create(['spam_count' => 0]);
    $entry = FormEntry::factory()->for($form)->create(['is_spam' => true]);

    app(MarkFormEntryAsLegitimateAction::class)->execute($entry);

    expect($form->fresh()->spam_count)->toBe(0);
});

test('mark form entry as legitimate action resolves entry by id', function (): void {
    $form = Form::factory()->create(['spam_count' => 2]);
    $entry = FormEntry::factory()->for($form)->create(['is_spam' => true]);

    $result = app(MarkFormEntryAsLegitimateAction::class)->execute($entry->id);

    expect($result->is_spam)->toBeFalse()
        ->and($form->fresh()->spam_count)->toBe(1);
});
