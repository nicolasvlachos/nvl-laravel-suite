<?php

declare(strict_types=1);

use Nvl\Forms\Actions\FormEntry\AddFormEntrySecurityFlagAction;
use Nvl\Forms\Actions\FormEntry\MarkFormEntryAsLegitimateAction;
use Nvl\Forms\Actions\FormEntry\MarkFormEntryAsSpamAction;
use Nvl\Forms\Builders\FormAnalyticBuilder;
use Nvl\Forms\Builders\FormEntryBuilder;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;
use Nvl\Forms\Models\FormEntry;

test('form entry model derives full name and contact info', function (): void {
    $entry = FormEntry::factory()->create([
        'first_name' => 'Jamie',
        'last_name' => 'Doe',
        'email' => 'jamie@example.com',
        'phone' => null,
    ]);

    expect($entry->full_name)->toBe('Jamie Doe')
        ->and($entry->hasContactInfo())->toBeTrue();
});

test('form entry model spam helpers adjust counters', function (): void {
    $form = Form::factory()->create(['spam_count' => 0]);
    $entry = FormEntry::factory()->for($form)->create(['is_spam' => false]);

    app(MarkFormEntryAsSpamAction::class)->execute($entry, 'bot');
    $entry->refresh();
    $form->refresh();

    expect($entry->is_spam)->toBeTrue()
        ->and($entry->getSecurityFlag('spam_reason'))->toBe('bot')
        ->and($form->spam_count)->toBe(1);

    app(MarkFormEntryAsLegitimateAction::class)->execute($entry);
    $entry->refresh();
    $form->refresh();

    expect($entry->is_spam)->toBeFalse()
        ->and($form->spam_count)->toBe(0);
});

test('form entry model stores additional security flags', function (): void {
    $entry = FormEntry::factory()->create(['security_flags' => null]);

    app(AddFormEntrySecurityFlagAction::class)->execute($entry, 'geo', 'EU');
    $entry->refresh();

    expect($entry->getSecurityFlag('geo'))->toBe('EU')
        ->and($entry->getSecurityFlag('missing', 'fallback'))->toBe('fallback');
});

test('form custom builders expose chainable filters', function (): void {
    $entryQuery = FormEntry::query();
    $analyticQuery = FormAnalytic::query();

    expect($entryQuery
        ->withEmail()
        ->fromDomain('example.com')
        ->recent()
        ->legitimate()
        ->spam()
        ->fromIp('127.0.0.1'))
        ->toBe($entryQuery)
        ->toBeInstanceOf(FormEntryBuilder::class)
        ->and($analyticQuery
            ->views()
            ->submissions()
            ->spamBlocked()
            ->today()
            ->thisWeek()
            ->thisMonth())
        ->toBe($analyticQuery)
        ->toBeInstanceOf(FormAnalyticBuilder::class);
});
