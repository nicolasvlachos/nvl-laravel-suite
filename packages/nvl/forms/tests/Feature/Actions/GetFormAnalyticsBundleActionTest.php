<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\GetFormAnalyticsBundleAction;
use Nvl\Forms\Actions\Form\RecordFormAnalyticAction;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

test('get form analytics bundle action returns analytics bundle with recent entries', function (): void {
    $form = Form::factory()->create();
    FormEntry::factory()->count(6)->for($form)->create();

    $recordAnalytic = app(RecordFormAnalyticAction::class);
    $recordAnalytic->execute($form->id, FormAnalyticEventType::VIEW, 'https://landing.example.com');
    $recordAnalytic->execute($form->id, FormAnalyticEventType::SUBMISSION);
    $recordAnalytic->execute($form->id, FormAnalyticEventType::SPAM_BLOCKED);

    $bundle = app(GetFormAnalyticsBundleAction::class)->execute($form, 30);

    expect($bundle['form'])->toBeInstanceOf(Form::class);
    expect($bundle['analytics']['total_views'])->toBe(1);
    expect($bundle['analytics']['total_submissions'])->toBe(1);
    expect($bundle['analytics']['spam_blocked'])->toBe(1);
    expect($bundle['recent_entries'])->toBeArray();
    expect($bundle['recent_entries'])->toHaveCount(5);
    expect($bundle['recent_entries'][0])->toBeArray();
    expect($bundle['recent_entries'][0])->toHaveKey('id');
});
