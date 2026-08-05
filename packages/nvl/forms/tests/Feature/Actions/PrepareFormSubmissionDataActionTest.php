<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Forms\Actions\Form\PrepareFormSubmissionDataAction;
use Nvl\Forms\Data\FormEntryPayload;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;
use Nvl\Forms\Models\Form;

test('prepare form submission data action maps validated payload', function (): void {
    $form = Form::factory()->create();

    $data = SubmitFormPayload::from([
        'subject' => 'Hello',
        'firstName' => 'Jamie',
        'submissionData' => ['plan' => 'pro'],
    ]);

    $request = Request::create('/forms', 'POST', server: [
        'HTTP_ORIGIN' => 'https://landing.example.com',
    ]);

    $entryData = app(PrepareFormSubmissionDataAction::class)->execute($form, $data, formSubmissionContext($request));

    expect($entryData)->toBeInstanceOf(FormEntryPayload::class);
    expect($entryData->subject)->toBe('Hello')
        ->and($entryData->firstName)->toBe('Jamie')
        ->and($entryData->submittedFrom)->toBe('landing.example.com')
        ->and($entryData->submissionData)->toBe(['plan' => 'pro']);
});

test('prepare form submission data falls back to request headers', function (): void {
    $form = Form::factory()->create();

    $data = SubmitFormPayload::from(['subject' => 'Header derived']);

    $server = ['HTTP_ORIGIN' => 'https://origin.example.com'];
    $request = Request::create('/forms', 'POST', [], [], [], $server);

    $entryData = app(PrepareFormSubmissionDataAction::class)->execute($form, $data, formSubmissionContext($request));

    expect($entryData->submittedFrom)->toBe('origin.example.com');
});

test('prepare form submission data accepts the canonical payload', function (): void {
    $form = Form::factory()->create();

    $data = SubmitFormPayload::from([
        'subject' => 'Camel payload',
        'firstName' => 'Alex',
        'lastName' => 'Doe',
        'phone' => '+359888000111',
        'submissionData' => ['orderCode' => 'ABC123'],
    ]);
    $server = ['HTTP_X_FORM_ORIGIN' => 'iframe.embed.example'];
    $request = Request::create('/forms', 'POST', [], [], [], $server);

    $entryData = app(PrepareFormSubmissionDataAction::class)->execute($form, $data, formSubmissionContext($request));

    expect($entryData->firstName)->toBe('Alex')
        ->and($entryData->lastName)->toBe('Doe')
        ->and($entryData->phone)->toBe('+359888000111')
        ->and($entryData->submissionData)->toBe(['orderCode' => 'ABC123'])
        ->and($entryData->submittedFrom)->toBe('iframe.embed.example');
});

test('prepare form submission data falls back to request host when origin headers are absent', function (): void {
    $form = Form::factory()->create();

    $request = Request::create('https://forms.example.test/forms', 'POST');

    $entryData = app(PrepareFormSubmissionDataAction::class)->execute(
        $form,
        SubmitFormPayload::from(['subject' => 'Host fallback']),
        formSubmissionContext($request),
    );

    expect($entryData->submittedFrom)->toBe('forms.example.test');
});
