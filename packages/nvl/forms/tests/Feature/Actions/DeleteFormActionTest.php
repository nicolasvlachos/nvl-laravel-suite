<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\DeleteFormAction;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;

test('delete form action removes a form without dependencies', function (): void {
    $form = Form::factory()->create();

    $result = app(DeleteFormAction::class)->execute($form);

    expect($result)->toBeTrue();
    $this->assertSoftDeleted(FormsTables::FORMS, ['id' => $form->id]);
});

test('delete form action throws when form has entries', function (): void {
    $form = Form::factory()->create();
    FormEntry::factory()->for($form)->create();

    $this->expectException(Exception::class);
    $this->expectExceptionMessage(trans('forms::forms/messages.error.cannot_delete_with_entries'));

    app(DeleteFormAction::class)->execute($form);
});
