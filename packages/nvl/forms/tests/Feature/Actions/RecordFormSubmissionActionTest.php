<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\RecordFormSubmissionAction;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;

test('record form submission action increments counters and analytics', function (): void {
    $form = Form::factory()->create([
        'submissions_count' => 0,
        'first_used_at' => null,
    ]);

    $result = app(RecordFormSubmissionAction::class)->execute(
        $form,
        'https://landing.example.com',
        '127.0.0.1',
        'Mozilla/5.0',
        'session-1234'
    );

    expect($result->submissions_count)->toBe(1)
        ->and($result->first_used_at)->not->toBeNull()
        ->and($result->last_used_at)->not->toBeNull();

    $this->assertDatabaseHas(FormAnalytic::query()->getModel()->getTable(), [
        'form_id' => $form->id,
        'event_type' => FormAnalyticEventType::SUBMISSION->value,
    ]);
});
