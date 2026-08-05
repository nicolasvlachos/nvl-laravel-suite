<?php

declare(strict_types=1);

use Nvl\Forms\Actions\FormEntry\ListFormEntriesAction;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

test('list form entries action filters by form and paginates', function (): void {
    $form = Form::factory()->create();
    FormEntry::factory()->count(3)->for($form)->create();
    FormEntry::factory()->create(); // Different form

    $paginator = app(ListFormEntriesAction::class)->execute($form, true, 2);

    expect($paginator->total())->toBe(3)
        ->and($paginator->perPage())->toBe(2);
});
