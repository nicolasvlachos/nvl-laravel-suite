<?php

declare(strict_types=1);

use Nvl\Forms\Actions\FormEntry\PersistFormEntryAction;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

test('persist form entry action stores legitimate submissions and updates counters', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'submissions_count' => 0,
    ]);

    $data = FormEntryPayload::from([
        'formId' => $form->id,
        'subject' => 'Inquiry',
        'email' => 'user@example.com',
        'submittedFrom' => 'landing.example.com',
    ]);

    $result = app(PersistFormEntryAction::class)->execute(
        $form,
        $data,
        ['is_spam' => false, 'score' => 10, 'flags' => []],
        '127.0.0.1',
        'Mozilla/5.0',
        'session-xyz'
    );

    expect($result['entry'])->toBeInstanceOf(FormEntry::class)
        ->and($result['form']->submissions_count)->toBe(1);

    $this->assertDatabaseHas(FormsTables::Entries, [
        'id' => $result['entry']->id,
        'form_id' => $form->id,
        'is_spam' => false,
    ]);
});

test('persist form entry action records spam submissions separately', function (): void {
    $form = Form::factory()->create([
        'spam_count' => 0,
    ]);

    $data = FormEntryPayload::from([
        'formId' => $form->id,
        'subject' => 'Spam Attempt',
        'submittedFrom' => 'spam.example.com',
    ]);

    $result = app(PersistFormEntryAction::class)->execute(
        $form,
        $data,
        ['is_spam' => true, 'score' => 80, 'flags' => ['reason' => 'spam']],
        '127.0.0.1',
        'Mozilla/5.0',
        null
    );

    expect($result['entry']->is_spam)->toBeTrue();

    $form->refresh();
    expect($form->spam_count)->toBe(1)
        ->and($form->submissions_count)->toBe(0);
});
