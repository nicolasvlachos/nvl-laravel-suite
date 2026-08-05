<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Forms\Actions\Form\GuardCustomFormSubmissionAction;
use Nvl\Forms\Data\Mutations\SubmitFormPayload;
use Nvl\Forms\Enums\FormAnalyticEventType;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormAnalytic;
use Nvl\Forms\Support\CustomFormGuardResult;

test('guard custom form submission allows clean submission', function (): void {
    $form = Form::factory()->create([
        'enable_honeypot' => false,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
    ]);

    $data = SubmitFormPayload::from([
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'email' => 'jane@example.com',
        'submittedFrom' => 'https://example.com',
    ]);

    $request = Request::create('/submit', 'POST');
    $request->server->set('REMOTE_ADDR', '10.0.0.1');

    $result = app(GuardCustomFormSubmissionAction::class)->execute($form, $data, formSubmissionContext($request));

    expect($result)->toBeInstanceOf(CustomFormGuardResult::class)
        ->and($result->handlerPayload)->toBeArray()
        ->and($result->handlerPayload['email'])->toBe('jane@example.com');
});

test('guard custom form submission blocks honeypot trigger', function (): void {
    config(['forms.security.spam_protection.honeypot.field_names' => ['website']]);

    $form = Form::factory()->create([
        'enable_honeypot' => true,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
    ]);

    $data = SubmitFormPayload::from([
        'firstName' => 'Bot',
        'email' => 'bot@spam.com',
        'submissionData' => ['website' => 'http://spam.com'],
        'submittedFrom' => 'https://example.com',
    ]);

    $request = Request::create('/submit', 'POST');
    $request->server->set('REMOTE_ADDR', '10.0.0.2');

    expect(fn () => app(GuardCustomFormSubmissionAction::class)->execute($form, $data, formSubmissionContext($request)))
        ->toThrow(FormSubmissionRejectionException::class);

    expect($form->fresh()->spam_count)->toBe(1);

    $analytic = FormAnalytic::firstOrFail();
    expect($analytic->event_type)->toBe(FormAnalyticEventType::SPAM_BLOCKED)
        ->and($analytic->metadata)->toMatchArray([
            'reason' => 'honeypot',
            'score' => 100,
            'flags' => ['honeypot' => true],
            'channel' => 'custom_resolvement',
        ]);
});

test('guard custom form submission records spam-score rejection metadata', function (): void {
    $form = Form::factory()->create([
        'enable_honeypot' => false,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
        'spam_count' => 0,
    ]);

    $data = SubmitFormPayload::from([
        'email' => 'bot@tempmail.com',
        'subject' => 'WIN BIG BITCOIN NOW',
        'submissionData' => [
            'body' => 'Limited time offer! click here http://a.test http://b.test http://c.test!!!',
        ],
        'submittedFrom' => 'https://example.com',
    ]);

    $request = Request::create('/submit', 'POST');
    $request->server->set('REMOTE_ADDR', '10.0.0.22');
    $request->headers->set('User-Agent', 'curl/8.0');

    expect(fn () => app(GuardCustomFormSubmissionAction::class)->execute($form, $data, formSubmissionContext($request)))
        ->toThrow(FormSubmissionRejectionException::class);

    expect($form->fresh()->spam_count)->toBe(1);

    $analytic = FormAnalytic::firstOrFail();
    expect($analytic->event_type)->toBe(FormAnalyticEventType::SPAM_BLOCKED)
        ->and($analytic->metadata['reason'])->toBe('spam_score')
        ->and($analytic->metadata['channel'])->toBe('custom_resolvement')
        ->and($analytic->metadata['flags'])->toHaveKey('suspicious_user_agent');
});

test('guard custom form submission includes normalized handler payload', function (): void {
    $form = Form::factory()->create([
        'enable_honeypot' => false,
        'enable_rate_limiting' => false,
        'restrict_public_access' => false,
    ]);

    $data = SubmitFormPayload::from([
        'firstName' => 'Alice',
        'lastName' => 'Smith',
        'email' => 'alice@example.com',
        'submittedFrom' => 'https://example.com',
    ]);

    $request = Request::create('/submit', 'POST');
    $request->server->set('REMOTE_ADDR', '10.0.0.3');

    $result = app(GuardCustomFormSubmissionAction::class)->execute($form, $data, formSubmissionContext($request));

    expect($result->handlerPayload)->toHaveKeys(['firstName', 'email', 'lastName'])
        ->not->toHaveKeys(['first_name', 'last_name']);
});
