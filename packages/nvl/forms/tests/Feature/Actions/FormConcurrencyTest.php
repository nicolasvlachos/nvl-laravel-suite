<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\UpdateFormAction;
use Nvl\Forms\Data\Mutations\MutateFormPayload;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Models\Form;

test('form updates reject stale revisions', function (): void {
    $form = Form::factory()->create();

    app(UpdateFormAction::class)->execute($form, MutateFormPayload::from([
        'handle' => 'first-writer',
        'expectedRevision' => 1,
    ]));

    expect(fn () => app(UpdateFormAction::class)->execute($form, MutateFormPayload::from([
        'handle' => 'stale-writer',
        'expectedRevision' => 1,
    ])))->toThrow(FormException::class, 'changed by another writer');
});
