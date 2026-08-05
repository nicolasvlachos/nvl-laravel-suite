<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Nvl\Forms\Actions\Form\ValidateFormSubmissionProtectionAction;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Services\PublicFormTokenService;

test('validate submission protection allows when csrf is not required', function (): void {
    $form = Form::factory()->create(['require_csrf' => false]);
    $request = Request::create('/submit', 'POST');

    app(ValidateFormSubmissionProtectionAction::class)->execute($form, formSubmissionContext($request));

    expect(true)->toBeTrue();
});

test('validate submission protection allows with valid csrf token', function (): void {
    $form = Form::factory()->create(['require_csrf' => true]);
    $request = Request::create('/submit', 'POST');

    $session = app('session.store');
    $session->start();
    $request->setLaravelSession($session);

    $token = $session->token();
    $request->headers->set('X-CSRF-TOKEN', $token);

    app(ValidateFormSubmissionProtectionAction::class)->execute($form, formSubmissionContext($request));

    expect(true)->toBeTrue();
});

test('validate submission protection allows with valid public token', function (): void {
    $form = Form::factory()->create(['require_csrf' => true]);
    $tokenService = app(PublicFormTokenService::class);
    $token = $tokenService->issue($form, CarbonImmutable::now()->addHour());

    $request = Request::create('/submit', 'POST');
    $request->headers->set(PublicFormTokenService::HEADER, $token);

    app(ValidateFormSubmissionProtectionAction::class)->execute($form, formSubmissionContext($request));

    expect(true)->toBeTrue();
});

test('validate submission protection rejects when csrf required and no token', function (): void {
    $form = Form::factory()->create(['require_csrf' => true]);
    $request = Request::create('/submit', 'POST');

    expect(fn () => app(ValidateFormSubmissionProtectionAction::class)->execute($form, formSubmissionContext($request)))
        ->toThrow(FormSubmissionRejectionException::class);
});

test('validate submission protection rejects expired public token', function (): void {
    $form = Form::factory()->create(['require_csrf' => true]);
    $tokenService = app(PublicFormTokenService::class);
    $token = $tokenService->issue($form, CarbonImmutable::now()->subMinute());

    $request = Request::create('/submit', 'POST');
    $request->headers->set(PublicFormTokenService::HEADER, $token);

    expect(fn () => app(ValidateFormSubmissionProtectionAction::class)->execute($form, formSubmissionContext($request)))
        ->toThrow(FormSubmissionRejectionException::class);
});
