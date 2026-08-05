<?php

declare(strict_types=1);

use Nvl\Forms\Actions\FormEntry\AnonymizeFormEntryAction;
use Nvl\Forms\Actions\FormEntry\RedactFormEntryAction;
use Nvl\Forms\Models\FormEntry;

test('entry redaction only clears explicitly allowlisted fields', function (): void {
    $entry = FormEntry::factory()->create([
        'email' => 'person@example.test',
        'first_name' => 'Person',
        'subject' => 'Keep this',
    ]);

    $redacted = app(RedactFormEntryAction::class)->execute($entry, ['email', 'first_name']);

    expect($redacted->email)->toBeNull()
        ->and($redacted->first_name)->toBeNull()
        ->and($redacted->subject)->toBe('Keep this')
        ->and($redacted->redacted_at)->not->toBeNull();
});

test('entry anonymization removes identifying and arbitrary submission data', function (): void {
    $entry = FormEntry::factory()->create([
        'email' => 'person@example.test',
        'submission_data' => ['account' => 'sensitive'],
        'ip_address' => '127.0.0.1',
    ]);

    $anonymized = app(AnonymizeFormEntryAction::class)->execute($entry);

    expect($anonymized->email)->toBeNull()
        ->and($anonymized->submission_data)->toBeNull()
        ->and($anonymized->ip_address)->toBeNull()
        ->and($anonymized->anonymized_at)->not->toBeNull();
});
