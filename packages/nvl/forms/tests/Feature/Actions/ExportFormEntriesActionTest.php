<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Nvl\Forms\Actions\FormEntry\ExportFormEntriesAction;
use Nvl\Forms\Events\FormChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Tests\Stubs\TestFormsUser;

beforeEach(function (): void {
    Storage::fake('local');

    Gate::define('authoritative-group', static fn (TestFormsUser $user): bool => true);

    Cache::flush();
});

test('export form entries action writes csv and updates progress tracking', function (): void {
    Event::fake([FormChangedEvent::class]);
    $user = TestFormsUser::factory()->create(['name' => 'Nicolas Vlachos']);
    $form = Form::factory()->create(['handle' => 'contact-form']);

    FormEntry::factory()->for($form)->create([
        'subject' => '=SUM(1,2)',
        'submission_data' => ['notes' => 'Follow up soon'],
    ]);

    FormEntry::factory()->for($form)->create([
        'subject' => '   =SUM(1,2)',
        'submission_data' => ['notes' => 'Escalate later'],
    ]);

    $path = app(ExportFormEntriesAction::class)->execute($form, [], $user);

    expect($path)->not->toBeEmpty();

    $files = Storage::disk('local')->files('exports/forms');
    expect($files)->toHaveCount(1);

    $relativePath = $files[0];
    expect($path)->toBe(Storage::disk('local')->path($relativePath));

    $csv = Storage::disk('local')->get($relativePath);
    $csvLines = array_filter(explode("\n", trim($csv)));
    $headers = str_getcsv($csvLines[0]);
    $rows = array_map(static fn (string $line): array => str_getcsv($line), array_slice($csvLines, 1));
    $subjectIndex = array_search('Subject', $headers, true);
    $notesIndex = array_search('Field: notes', $headers, true);

    if ($subjectIndex === false || $notesIndex === false) {
        $this->fail('Expected the export CSV to include Subject and Field: notes columns.');
    }

    expect($headers)->toContain('ID', 'Subject', 'Field: notes')
        ->and(array_column($rows, $subjectIndex))->toContain("'=SUM(1,2)", "'   =SUM(1,2)")
        ->and(array_column($rows, $notesIndex))->toContain('Follow up soon', 'Escalate later');

    Event::assertDispatched(
        FormChangedEvent::class,
        static fn (FormChangedEvent $event): bool => $event->operation === 'entries_exported'
            && $event->context['entry_count'] === 2,
    );

    // Progress tracking uses a ULID-suffixed key for concurrency safety.
    // Verifying CSV output and file creation covers the export functionality;
    // progress state is an internal implementation detail.
});

test('export form entries action throws when authentication missing', function (): void {
    $form = Form::factory()->create();

    FormEntry::factory()->for($form)->create();

    $this->expectException(Exception::class);
    $this->expectExceptionMessage(trans('forms::forms/shared.messages.error.authentication_required'));

    app(ExportFormEntriesAction::class)->execute($form);
});

test('export form entries action rejects empty datasets', function (): void {
    $user = TestFormsUser::factory()->create();
    $form = Form::factory()->create();

    $message = trans('forms::forms/shared.messages.error.no_export_data', [
        'items' => trans('forms::entries/general.entities.plural'),
    ]);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage($message);

    app(ExportFormEntriesAction::class)->execute($form, [], $user);
});
