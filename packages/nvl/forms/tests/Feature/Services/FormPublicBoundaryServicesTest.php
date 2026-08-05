<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Nvl\Forms\Enums\FormSubmissionReceiptState;
use Nvl\Forms\Exceptions\FormException;
use Nvl\Forms\Exceptions\FormSubmissionRejectionException;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Services\CustomSubmissionReceiptService;
use Nvl\Forms\Services\EntryCallbackRegistry;
use Nvl\Forms\Services\PublicFormTokenService;
use Nvl\Forms\Services\RequestOriginResolver;
use Nvl\Forms\Support\FormSubmissionContext;
use Nvl\Forms\Tests\Stubs\FormsTestContractCallback;
use Nvl\Forms\Tests\Stubs\FormsTestExecuteCallback;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-02 12:00:00');
    FormsTestContractCallback::$calls = 0;
    FormsTestExecuteCallback::$calls = 0;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('public tokens validate form scope handle expiry and malformed inputs', function (): void {
    $key = 'forms-public-boundary-key';
    config()->set('app.key', 'base64:'.base64_encode($key));
    $form = Form::factory()->create(['handle' => 'public-token-form']);
    $other = Form::factory()->create(['handle' => 'other-form']);
    $service = app(PublicFormTokenService::class);
    $token = $service->issue($form, now()->addMinutes(5));

    expect($service->validate($token, $form))->toBeTrue()
        ->and($service->validate($token, $other))->toBeFalse()
        ->and($service->issuedAt($token, $form))->toBe((float) now()->timestamp)
        ->and($service->issuedAt($token, $other))->toBeNull()
        ->and($service->validateForHandle($token, 'public-token-form'))->toBeTrue()
        ->and($service->validateForHandle($token, 'other-form'))->toBeFalse()
        ->and($service->validate(null, $form))->toBeFalse()
        ->and($service->validate(' ', $form))->toBeFalse()
        ->and($service->validate('missing-separator', $form))->toBeFalse()
        ->and($service->validate('.signature', $form))->toBeFalse()
        ->and($service->validate($token.'tampered', $form))->toBeFalse();

    $sign = static function (string $raw, string $signingKey): string {
        $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, $signingKey, true)), '+/', '-_'), '=');

        return $encoded.'.'.$signature;
    };
    $future = now()->addMinute()->timestamp;

    expect($service->validate($sign('{invalid', $key), $form))->toBeFalse()
        ->and($service->validate($sign(json_encode('scalar', JSON_THROW_ON_ERROR), $key), $form))->toBeFalse()
        ->and($service->validate($sign(json_encode(['form_id' => 1], JSON_THROW_ON_ERROR), $key), $form))->toBeFalse()
        ->and($service->validate($sign(json_encode([
            'form_id' => $form->id,
            'iat' => now()->timestamp,
            'exp' => now()->subSecond()->timestamp,
            'nonce' => 'expired',
        ], JSON_THROW_ON_ERROR), $key), $form))->toBeFalse()
        ->and($service->validateForHandle($sign(json_encode([
            'form_id' => '',
            'iat' => now()->timestamp,
            'exp' => $future,
            'nonce' => 'empty-form',
        ], JSON_THROW_ON_ERROR), $key), 'public-token-form'))->toBeFalse()
        ->and($service->issuedAt($sign(json_encode([
            'form_id' => $form->id,
            'iat' => 0,
            'exp' => $future,
            'nonce' => 'zero-issued-at',
        ], JSON_THROW_ON_ERROR), $key), $form))->toBeNull();

    config()->set('app.key', 'base64:not-valid-base64***');
    $fallbackToken = $service->issue($form, now()->addMinute());
    expect($service->validate($fallbackToken, $form))->toBeTrue();
});

test('submission context normalizes every supported transport source', function (): void {
    $session = new Store('forms-test', new ArraySessionHandler(120));
    $session->start();
    $session->regenerateToken();
    $request = Request::create('https://forms.test/submit', 'POST', server: [
        'REMOTE_ADDR' => '192.0.2.30',
        'HTTP_USER_AGENT' => 'Consumer/2.0',
        'HTTP_ORIGIN' => 'https://embed.example.test:8443',
        'HTTP_X_CSRF_TOKEN' => ' csrf-header ',
        'HTTP_X_FORMS_PUBLIC_TOKEN' => ' public-header ',
        'HTTP_IDEMPOTENCY_KEY' => ' retry-1 ',
    ]);
    $request->setLaravelSession($session);
    $request->setUserResolver(static fn (): GenericUser => new GenericUser(['id' => 1]));

    $context = FormSubmissionContext::fromRequest($request, app(RequestOriginResolver::class));

    expect($context->ipAddress)->toBe('192.0.2.30')
        ->and($context->userAgent)->toBe('Consumer/2.0')
        ->and($context->sessionId)->toBe($session->getId())
        ->and($context->sessionToken)->toBe($session->token())
        ->and($context->csrfToken)->toBe('csrf-header')
        ->and($context->publicToken)->toBe('public-header')
        ->and($context->idempotencyKey)->toBe('retry-1')
        ->and($context->originHost)->toBe('embed.example.test:8443')
        ->and($context->originHeader)->toBe('https://embed.example.test:8443')
        ->and($context->requestHost)->toBe('forms.test')
        ->and($context->actor)->toBeInstanceOf(GenericUser::class)
        ->and($context->resolvedIpAddress())->toBe('192.0.2.30')
        ->and($context->httpRequest())->toBe($request);

    $xsrf = Request::create('/', 'POST', [
        'public_token' => 'body-public',
        'idempotencyKey' => 'body-retry',
    ], server: ['HTTP_X_XSRF_TOKEN' => 'encoded%20csrf']);
    $xsrfContext = FormSubmissionContext::fromRequest($xsrf, app(RequestOriginResolver::class));
    expect($xsrfContext->csrfToken)->toBe('encoded csrf')
        ->and($xsrfContext->publicToken)->toBe('body-public')
        ->and($xsrfContext->idempotencyKey)->toBe('body-retry');

    $body = Request::create('/', 'POST', [
        '_token' => ' body-csrf ',
        'publicToken' => ' body-token ',
        'idempotencyKey' => str_repeat('x', 129),
    ]);
    $bodyContext = FormSubmissionContext::fromRequest($body, app(RequestOriginResolver::class));
    expect($bodyContext->csrfToken)->toBe('body-csrf')
        ->and($bodyContext->publicToken)->toBe('body-token')
        ->and($bodyContext->idempotencyKey)->toBeNull();

    $fallback = new FormSubmissionContext(ipAddress: '', request: null);
    expect($fallback->resolvedIpAddress())->toBe('0.0.0.0')
        ->and($fallback->httpRequest())->toBeInstanceOf(Request::class);
});

test('custom submission receipts enforce durable replay and registration claims', function (): void {
    $service = app(CustomSubmissionReceiptService::class);
    $multiple = Form::factory()->create(['allow_multiple_registrations' => true]);

    expect($service->claim($multiple, ['subject' => 'No identity'], null, null))->toBeNull();

    $claim = $service->claim($multiple, ['subject' => 'Stable'], null, 'retry-key');
    expect($claim)->not->toBeNull()
        ->and($claim?->isReplay)->toBeFalse();

    $service->complete($claim, 'downstream-1');
    $replay = $service->claim($multiple, ['subject' => 'Stable'], null, 'retry-key');
    expect($replay?->isReplay)->toBeTrue()
        ->and($replay?->receipt->result_id)->toBe('downstream-1');

    $service->complete(null, 'ignored');
    $service->complete($replay, 'ignored');
    $service->fail(null);
    $service->fail($replay);

    expect(fn () => $service->claim($multiple, ['subject' => 'Changed'], null, 'retry-key'))
        ->toThrow(FormSubmissionRejectionException::class);

    $processing = $service->claim($multiple, ['subject' => 'Processing'], null, 'processing-key');
    expect(fn () => $service->claim($multiple, ['subject' => 'Processing'], null, 'processing-key'))
        ->toThrow(FormSubmissionRejectionException::class);
    $service->fail($processing);
    expect($processing?->receipt->refresh()->state)->toBe(FormSubmissionReceiptState::Failed);

    $single = Form::factory()->create(['allow_multiple_registrations' => false]);
    $singleClaim = $service->claim($single, ['email' => 'Person@Example.test'], null, null);
    $service->complete($singleClaim, 'single-1');
    expect(fn () => $service->claim($single, ['email' => ' person@example.test '], null, null))
        ->toThrow(FormSubmissionRejectionException::class)
        ->and(fn () => $service->claim($single, ['subject' => 'Missing identity'], null, null))
        ->toThrow(FormSubmissionRejectionException::class);
});

test('entry callback registry supports contracts container callbacks callables and lifecycle controls', function (): void {
    $form = Form::factory()->create(['handle' => 'callback-contract']);
    $entry = FormEntry::factory()->for($form)->create();
    $request = Request::create('/submit', 'POST');
    $registry = app(EntryCallbackRegistry::class);
    $closureCalls = 0;
    $arrayCallback = new class
    {
        public int $calls = 0;

        public function execute(Form $form, FormEntry $entry, Request $request): void
        {
            $this->calls++;
        }
    };

    expect(fn () => $registry->register(' ', static fn (): null => null))
        ->toThrow(FormException::class, 'non-empty');

    $registry->register('callback-contract', []);
    $registry->register('callback-contract', [new stdClass]);
    $contract = new FormsTestContractCallback;
    $registry->register('callback-contract', [
        $contract,
        FormsTestExecuteCallback::class,
        static function () use (&$closureCalls): void {
            $closureCalls++;
        },
        [$arrayCallback, 'execute'],
    ]);

    expect(fn () => $registry->register('callback-contract', $contract))
        ->toThrow(FormException::class, 'already registered');

    $registry->dispatch($form, $entry, $request);
    expect(FormsTestContractCallback::$calls)->toBe(1)
        ->and(FormsTestExecuteCallback::$calls)->toBe(1)
        ->and($closureCalls)->toBe(1)
        ->and($arrayCallback->calls)->toBe(1);

    $registry->forget(' ');
    $registry->forget('callback-contract');
    $registry->dispatch($form, $entry, $request);
    $registry->register('callback-contract', $contract);
    $registry->clear();
    $registry->dispatch($form, $entry, $request);

    expect(FormsTestContractCallback::$calls)->toBe(1);
});
