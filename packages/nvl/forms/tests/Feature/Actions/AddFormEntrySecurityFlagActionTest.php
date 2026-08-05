<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Nvl\Forms\Actions\FormEntry\AddFormEntrySecurityFlagAction;
use Nvl\Forms\Events\FormEntryChangedEvent;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Tests\Stubs\TestFormsUser;

test('add form entry security flag action persists a flag on the entry', function (): void {
    Event::fake([FormEntryChangedEvent::class]);
    $actor = TestFormsUser::factory()->create(['name' => 'Natali Necheva']);
    $this->actingAs($actor);

    $form = Form::factory()->create();
    $entry = FormEntry::factory()->for($form)->create(['security_flags' => null]);

    $result = app(AddFormEntrySecurityFlagAction::class)->execute($entry, 'flagged_reason', 'suspicious_timing');

    expect($result->security_flags)->toBeArray()
        ->and($result->getSecurityFlag('flagged_reason'))->toBe('suspicious_timing');

    Event::assertDispatched(
        FormEntryChangedEvent::class,
        static fn (FormEntryChangedEvent $event): bool => $event->operation === 'security_flag_added'
            && $event->entryId === $entry->id,
    );
});

test('add form entry security flag action preserves existing flags', function (): void {
    $entry = FormEntry::factory()->create([
        'security_flags' => ['existing_flag' => true],
    ]);

    $result = app(AddFormEntrySecurityFlagAction::class)->execute($entry, 'new_flag', 'value');

    expect($result->getSecurityFlag('existing_flag'))->toBeTrue()
        ->and($result->getSecurityFlag('new_flag'))->toBe('value');
});

test('add form entry security flag action resolves entry by id', function (): void {
    $entry = FormEntry::factory()->create(['security_flags' => null]);

    $result = app(AddFormEntrySecurityFlagAction::class)->execute($entry->id, 'test_key', 42);

    expect($result->id)->toBe($entry->id)
        ->and($result->getSecurityFlag('test_key'))->toBe(42);
});
