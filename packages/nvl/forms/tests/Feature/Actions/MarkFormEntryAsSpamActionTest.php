<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Nvl\Forms\Actions\FormEntry\MarkFormEntryAsSpamAction;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

test('mark form entry as spam action sets is_spam and increments form counter', function (): void {
    Event::fake([FormEntryChangedEvent::class]);
    $form = Form::factory()->create(['spam_count' => 0]);
    $entry = FormEntry::factory()->for($form)->create(['is_spam' => false]);

    $result = app(MarkFormEntryAsSpamAction::class)->execute($entry);

    expect($result->is_spam)->toBeTrue()
        ->and($result->getSecurityFlag('marked_spam_at'))->not->toBeNull()
        ->and($result->form->spam_count)->toBe(1);

    Event::assertDispatched(FormEntryChangedEvent::class);
});

test('mark form entry as spam action stores optional reason', function (): void {
    $form = Form::factory()->create(['spam_count' => 0]);
    $entry = FormEntry::factory()->for($form)->create(['is_spam' => false]);

    $result = app(MarkFormEntryAsSpamAction::class)->execute($entry, 'obvious bot pattern');

    expect($result->getSecurityFlag('spam_reason'))->toBe('obvious bot pattern');
});

test('mark form entry as spam action resolves entry by id', function (): void {
    $form = Form::factory()->create(['spam_count' => 2]);
    $entry = FormEntry::factory()->for($form)->create(['is_spam' => false]);

    $result = app(MarkFormEntryAsSpamAction::class)->execute($entry->id);

    expect($result->is_spam)->toBeTrue()
        ->and($form->fresh()->spam_count)->toBe(3);
});
