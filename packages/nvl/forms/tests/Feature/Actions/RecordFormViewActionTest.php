<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\RecordFormViewAction;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;

test('record form view action updates counters and allowed origin usage', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => true,
        'views_count' => 0,
    ]);

    $origin = AllowedOrigin::factory()->for($form)->create([
        'origin' => '*.example.com',
        'usage_count' => 0,
    ]);

    $updated = app(RecordFormViewAction::class)->execute(
        $form,
        'https://shop.example.com',
        '127.0.0.1',
        'Mozilla/5.0',
        'session-1'
    );

    expect($updated->views_count)->toBe(1);

    $origin->refresh();
    expect($origin->usage_count)->toBe(1)
        ->and($origin->last_used_at)->not->toBeNull();

    $this->assertDatabaseHas(FormAnalytic::query()->getModel()->getTable(), [
        'form_id' => $form->id,
        'event_type' => FormAnalyticEventType::VIEW->value,
    ]);
});
