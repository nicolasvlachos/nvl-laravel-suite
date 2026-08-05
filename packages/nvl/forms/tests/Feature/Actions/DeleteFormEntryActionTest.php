<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Nvl\Forms\Actions\FormEntry\DeleteFormEntryAction;
use Nvl\Forms\Contracts\FormEntryDeletionPolicy;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

test('delete form entry action removes entry and updates counters', function (): void {
    Event::fake([FormEntryChangedEvent::class]);
    $form = Form::factory()->create(['submissions_count' => 1]);
    $entry = FormEntry::factory()->for($form)->create([
        'is_spam' => false,
        'created_at' => now(),
    ]);

    $deleted = app(DeleteFormEntryAction::class)->execute($entry);

    expect($deleted)->toBeTrue();
    $this->assertDatabaseMissing(FormsTables::FORM_ENTRIES, ['id' => $entry->id]);
    Event::assertDispatched(FormEntryChangedEvent::class);

    $form->refresh();
    expect($form->submissions_count)->toBe(0);
});

test('delete form entry action delegates legal holds to the deletion policy', function (): void {
    $form = Form::factory()->create();
    $entry = FormEntry::factory()->for($form)->create([
        'created_at' => now()->subYears(8),
    ]);

    app()->instance(FormEntryDeletionPolicy::class, new class implements FormEntryDeletionPolicy
    {
        public function authorize(FormEntry $entry, ?Authenticatable $actor = null): void
        {
            throw new Exception('Entry is under legal hold.');
        }
    });

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('Entry is under legal hold.');

    app(DeleteFormEntryAction::class)->execute($entry);
});
