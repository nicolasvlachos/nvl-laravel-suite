<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\GetFormAnalyticsSummaryAction;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;

test('get form analytics summary aggregates recent metrics', function (): void {
    $form = Form::factory()->create();

    $view = FormAnalytic::create([
        'form_id' => $form->id,
        'event_type' => FormAnalyticEventType::VIEW,
    ]);
    $submission = FormAnalytic::create([
        'form_id' => $form->id,
        'event_type' => FormAnalyticEventType::SUBMISSION,
    ]);
    $spam = FormAnalytic::create([
        'form_id' => $form->id,
        'event_type' => FormAnalyticEventType::SPAM_BLOCKED,
        'origin' => 'spam.example.com',
    ]);
    // Outside window
    $old = FormAnalytic::create([
        'form_id' => $form->id,
        'event_type' => FormAnalyticEventType::VIEW,
    ]);

    $view->forceFill(['created_at' => now()->subDays(5)])->save();
    $submission->forceFill(['created_at' => now()->subDays(3)])->save();
    $spam->forceFill(['created_at' => now()->subDays(2)])->save();
    $old->forceFill(['created_at' => now()->subDays(60)])->save();

    $summary = app(GetFormAnalyticsSummaryAction::class)->execute($form, 30);

    expect($summary['total_views'])->toBe(1)
        ->and($summary['total_submissions'])->toBe(1)
        ->and($summary['spam_blocked'])->toBe(1)
        ->and($summary['conversion_rate'])->toBe(100.0)
        ->and($summary['top_origins'])->toBe(['spam.example.com' => 1]);
});
