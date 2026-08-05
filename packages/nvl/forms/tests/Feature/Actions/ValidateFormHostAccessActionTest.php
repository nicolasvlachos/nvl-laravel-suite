<?php

declare(strict_types=1);

use Nvl\Forms\Actions\FormEntry\ValidateFormHostAccessAction;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

test('validate form host access passes when public access is unrestricted', function (): void {
    $form = Form::factory()->create(['restrict_public_access' => false]);

    app(ValidateFormHostAccessAction::class)->execute($form, null);

    expect(true)->toBeTrue();
});

test('validate form host access ensures provided origin is allowed', function (): void {
    $form = Form::factory()->create(['restrict_public_access' => true]);
    AllowedOrigin::factory()->for($form)->create(['origin' => '*.example.com']);

    app(ValidateFormHostAccessAction::class)->execute($form, 'https://shop.example.com');

    expect(true)->toBeTrue();
});

test('validate form host access throws when origin missing under restriction', function (): void {
    $form = Form::factory()->create(['restrict_public_access' => true]);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage(trans('forms::forms/messages.error.origin_required'));

    app(ValidateFormHostAccessAction::class)->execute($form, null);
});
