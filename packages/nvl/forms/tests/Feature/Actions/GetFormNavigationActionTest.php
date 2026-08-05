<?php

declare(strict_types=1);

use Nvl\Forms\Actions\FormEntry\GetFormNavigationAction;
use Nvl\Forms\Models\Form;

test('get form navigation action returns overview tab with expected href', function (): void {
    $form = Form::factory()->create();

    $navigation = app(GetFormNavigationAction::class)->execute($form, true);

    expect($navigation)
        ->toBeArray()
        ->and($navigation)->toHaveCount(1);

    $tab = $navigation[0];

    expect($tab['label'] ?? null)->toBe(trans('forms::forms/general.tabs.overview'))
        ->and($tab['href'] ?? null)->toBe("/forms/{$form->id}")
        ->and($tab['active'] ?? null)->toBeTrue();

    $inactive = app(GetFormNavigationAction::class)->execute($form, false);
    expect($inactive[0]['active'] ?? null)->toBeFalse();
});
