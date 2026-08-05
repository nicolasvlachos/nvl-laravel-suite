<?php

declare(strict_types=1);

use Nvl\Forms\Actions\Form\ListFormsAction;
use Nvl\Forms\Models\Form;

test('list forms action returns a paginator with the requested size', function (): void {
    Form::factory()->count(5)->create();

    $paginator = app(ListFormsAction::class)->execute(true, 2);

    expect($paginator->perPage())->toBe(2)
        ->and($paginator->total())->toBe(5);
});

test('list forms action can return a collection without pagination', function (): void {
    Form::factory()->count(3)->create();

    $collection = app(ListFormsAction::class)->execute(false);

    expect($collection)->toHaveCount(3);
});
