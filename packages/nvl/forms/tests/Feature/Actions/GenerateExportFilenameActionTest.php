<?php

declare(strict_types=1);

use Nvl\Forms\Actions\FormEntry\GenerateExportFilenameAction;
use Nvl\Forms\Models\Form;

test('generate export filename action delegates sanitized export filename generation', function (): void {
    $form = Form::factory()->create([
        'name' => 'Spring Promo 2025!',
        'handle' => 'Spring Promo 2025!',
    ]);

    $filename = app(GenerateExportFilenameAction::class)->execute($form);

    expect($filename)->toMatch('/Spring_Promo_2025_entries_guest_\\d{4}-\\d{2}-\\d{2}_\\d{2}-\\d{2}-\\d{2}\\.csv/');
    expect($filename)->toEndWith('.csv');
});
