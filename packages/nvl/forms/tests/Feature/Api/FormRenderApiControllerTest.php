<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Nvl\Forms\Contracts\CustomFormHandler;
use Nvl\Forms\Contracts\FormErrorMapper;
use Nvl\Forms\Contracts\FormRateLimiter;
use Nvl\Forms\Contracts\FormRenderDataProvider;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Models\FormEntry;
use Nvl\Forms\Models\FormRateLimit;
use Nvl\Forms\Results\FormRateLimitAttemptResult;
use Nvl\Forms\Services\PublicFormTokenService;
use Nvl\Forms\Support\FormErrorMapperRegistry;
use Nvl\Forms\Support\FormHandlerRegistry;
use Nvl\Forms\Support\FormRenderDataRegistry;
use Nvl\Support\Exceptions\BusinessException;

test('render endpoint returns form payload with csrf token', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
    ]);

    $response = $this->withSession([])->getJson("/api/v1/forms/{$form->id}/render");

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $form->id,
                'name' => $form->displayName(),
            ],
        ]);

    expect($response->json('csrf_token'))->toBeString()->not->toBe('');
    expect($response->json('public_token'))->toBeString()->not->toBe('');
});

test('public endpoints resolve handles and apply the requested content locale', function (): void {
    config([
        'translatable.locales' => ['en', 'bg'],
        'translatable.fallback_locales' => ['en'],
    ]);

    $form = Form::factory()->create([
        'handle' => 'localized-registration',
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'translations' => [
            'en' => ['name' => 'Registration'],
            'bg' => ['name' => 'Регистрация'],
        ],
    ]);

    $response = $this->getJson('/api/v1/forms/localized-registration/render?lang=bg-BG');

    $response->assertOk()
        ->assertJsonPath('data.id', $form->id)
        ->assertJsonPath('data.handle', 'localized-registration')
        ->assertJsonPath('data.locale', 'bg')
        ->assertJsonPath('data.name', 'Регистрация');
});

test('public routes reject unavailable forms before submission', function (): void {
    $form = Form::factory()->create([
        'handle' => 'paused-registration',
        'restrict_public_access' => false,
        'status' => FormStatus::PAUSED,
        'require_csrf' => false,
    ]);

    $response = $this->postJson('/api/v1/forms/paused-registration/submit', [
        'email' => 'paused@example.com',
    ]);

    $response->assertForbidden()
        ->assertJson([
            'success' => false,
            'error' => trans('forms::forms/messages.api.form_unavailable'),
        ]);

    $this->assertDatabaseCount(FormsTables::FORM_ENTRIES, 0);
});

test('render endpoint responds with not found when form is missing', function (): void {
    $uuid = (string) Str::uuid();

    $response = $this->getJson("/api/v1/forms/{$uuid}/render");

    $response->assertStatus(404)
        ->assertJsonStructure(['error']);
});

test('submit endpoint persists entries and returns payload', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'enable_rate_limiting' => false,
        'require_csrf' => false,
    ]);

    $payload = [
        'subject' => 'API submission',
        'email' => 'api@example.com',
    ];

    $response = $this->postJson(
        "/api/v1/forms/{$form->id}/submit",
        $payload,
        ['Origin' => 'https://landing.example.com']
    );

    $response->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => trans('forms::forms/messages.api.form_submitted'),
        ])
        ->assertJsonStructure(['data' => ['entry_id', 'form_name', 'submitted_at']]);

    $entry = FormEntry::where('form_id', $form->id)->first();
    expect($entry)->not->toBeNull();

    $form->refresh();
    expect($form->submissions_count)->toBe(1);
});

test('submit endpoint validates payloads and returns errors', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'require_csrf' => false,
    ]);

    $response = $this->postJson("/api/v1/forms/{$form->id}/submit", [
        'email' => 'not-an-email',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->assertDatabaseCount(FormsTables::FORM_ENTRIES, 0);
});

test('submit endpoint handles rate limit violations gracefully', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'enable_rate_limiting' => true,
        'rate_limit_per_hour' => 1,
        'require_csrf' => false,
    ]);

    $rateLimit = FormRateLimit::create([
        'form_id' => $form->id,
        'ip_address' => '127.0.0.1',
        'submission_count' => 1,
        'window_start' => now(),
        'last_submission_at' => now(),
        'is_blocked' => true,
        'blocked_until' => now()->addMinutes(15),
        'violation_count' => 1,
    ]);

    $this->mock(FormRateLimiter::class, function (MockInterface $mock) use ($rateLimit): void {
        $mock->shouldReceive('consumeSubmissionAttempt')
            ->once()
            ->with(Mockery::type(Form::class), '127.0.0.1', 'blocked.example.com', 'Symfony', null)
            ->andReturn(FormRateLimitAttemptResult::denied($rateLimit, 900));
    });

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->postJson(
            "/api/v1/forms/{$form->id}/submit",
            [
                'subject' => 'Rate limited',
            ],
            ['Origin' => 'https://blocked.example.com']
        );

    $response->assertTooManyRequests()
        ->assertJson([
            'success' => false,
            'error' => trans('forms::forms/shared.messages.error.rate_limit_exceeded'),
        ]);

    $this->assertDatabaseCount(FormsTables::FORM_ENTRIES, 0);
});

test('submit endpoint blocks custom handlers when rate limit is exceeded', function (): void {
    $handler = new class implements CustomFormHandler
    {
        public function handle(Form $form, array $data, Request $request): array
        {
            return ['entry_id' => 'custom-rate-limit'];
        }
    };

    $form = Form::factory()->create([
        'handle' => 'custom-rate-limit',
        'resolvement' => Resolvement::CUSTOM,
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'enable_rate_limiting' => true,
        'rate_limit_per_hour' => 1,
        'require_csrf' => false,
    ]);

    $handlerRegistry = app(FormHandlerRegistry::class);
    $handlerRegistry->clear();
    $handlerClass = get_class($handler);
    $handlerRegistry->register($form->handle, $handlerClass);
    app()->instance($handlerClass, $handler);

    $rateLimit = FormRateLimit::create([
        'form_id' => $form->id,
        'ip_address' => '127.0.0.1',
        'submission_count' => 1,
        'window_start' => now(),
        'last_submission_at' => now(),
        'is_blocked' => true,
        'blocked_until' => now()->addMinutes(15),
        'violation_count' => 1,
    ]);

    $this->mock(FormRateLimiter::class, function (MockInterface $mock) use ($rateLimit): void {
        $mock->shouldReceive('consumeSubmissionAttempt')
            ->once()
            ->with(Mockery::type(Form::class), '127.0.0.1', 'landing.example.com', 'Symfony', null)
            ->andReturn(FormRateLimitAttemptResult::denied($rateLimit, 900));
    });

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->postJson(
            "/api/v1/forms/{$form->id}/submit",
            ['subject' => 'Custom limited'],
            ['Origin' => 'https://landing.example.com']
        );

    $response->assertStatus(429)
        ->assertJson([
            'success' => false,
            'error' => trans('forms::forms/shared.messages.error.rate_limit_exceeded'),
        ]);

    $handlerRegistry->clear();
    app()->forgetInstance($handlerClass);
});

test('submit endpoint passes normalized payload to custom handlers', function (): void {
    $capturedPayload = [];

    $handler = new class($capturedPayload) implements CustomFormHandler
    {
        /**
         * @var array<string,mixed>
         */
        private array $capturedPayload;

        /**
         * @param  array<string,mixed>  $capturedPayload
         */
        public function __construct(array &$capturedPayload)
        {
            $this->capturedPayload = &$capturedPayload;
        }

        public function handle(Form $form, array $data, Request $request): array
        {
            $this->capturedPayload = $data;

            return ['entry_id' => 'custom-normalized'];
        }
    };

    $form = Form::factory()->create([
        'handle' => 'custom-normalized',
        'resolvement' => Resolvement::CUSTOM,
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'enable_rate_limiting' => false,
        'require_csrf' => false,
    ]);

    $handlerRegistry = app(FormHandlerRegistry::class);
    $handlerRegistry->clear();
    $handlerClass = get_class($handler);
    $handlerRegistry->register($form->handle, $handlerClass);
    app()->instance($handlerClass, $handler);

    $response = $this->postJson(
        "/api/v1/forms/{$form->id}/submit",
        [
            'subject' => 'Normalized payload',
            'firstName' => 'Taylor',
            'lastName' => 'Swift',
            'phone' => '+359888123456',
            'submissionData' => ['notes' => 'hello'],
        ],
        ['Origin' => 'https://landing.example.com/path']
    );

    $response->assertCreated()
        ->assertJson([
            'success' => true,
            'data' => ['entry_id' => 'custom-normalized'],
        ]);

    expect($capturedPayload['submittedFrom'] ?? null)->toBe('landing.example.com')
        ->and($capturedPayload['firstName'] ?? null)->toBe('Taylor')
        ->and($capturedPayload['submissionData']['notes'] ?? null)->toBe('hello')
        ->and($capturedPayload)->not->toHaveKeys([
            'submitted_from',
            'first_name',
            'submission_data',
        ]);

    $handlerRegistry->clear();
    app()->forgetInstance($handlerClass);
});

test('submit endpoint exposes a warning when custom submission bookkeeping degrades', function (): void {
    $handler = new class implements CustomFormHandler
    {
        public function handle(Form $form, array $data, Request $request): array
        {
            return ['entry_id' => 'custom-warning-api'];
        }
    };

    $form = Form::factory()->create([
        'handle' => 'custom-warning-api',
        'resolvement' => Resolvement::CUSTOM,
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'enable_rate_limiting' => false,
        'require_csrf' => false,
    ]);

    $handlerRegistry = app(FormHandlerRegistry::class);
    $handlerRegistry->clear();
    $handlerClass = get_class($handler);
    $handlerRegistry->register($form->handle, $handlerClass);
    app()->instance($handlerClass, $handler);

    DB::shouldReceive('transaction')
        ->once()
        ->andThrow(new RuntimeException('Forms bookkeeping failed'));

    $response = $this->postJson(
        "/api/v1/forms/{$form->id}/submit",
        ['subject' => 'Warning payload'],
        ['Origin' => 'https://landing.example.com']
    );

    $response->assertCreated()
        ->assertJson([
            'success' => true,
            'warning' => trans('forms::forms/messages.warning.submission_recording_delayed'),
            'data' => ['entry_id' => 'custom-warning-api'],
        ]);

    $handlerRegistry->clear();
    app()->forgetInstance($handlerClass);
});

test('submit endpoint rejects requests missing required submission protection', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'enable_rate_limiting' => false,
        'require_csrf' => true,
    ]);

    $response = $this->postJson(
        "/api/v1/forms/{$form->id}/submit",
        ['subject' => 'Missing token'],
        ['Origin' => 'https://landing.example.com']
    );

    $response->assertStatus(419)
        ->assertJson([
            'success' => false,
            'error' => trans('forms::forms/messages.error.csrf_failed'),
        ]);
});

test('submit endpoint accepts valid public token when submission protection is required', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'enable_rate_limiting' => false,
        'require_csrf' => true,
    ]);

    /** @var PublicFormTokenService $tokenService */
    $tokenService = app(PublicFormTokenService::class);
    $token = $tokenService->issue($form, now()->addMinutes(15));

    $response = $this->postJson(
        "/api/v1/forms/{$form->id}/submit",
        [
            'subject' => 'Protected submission',
            'email' => 'protected@example.com',
        ],
        [
            'Origin' => 'https://landing.example.com',
            PublicFormTokenService::HEADER => $token,
        ]
    );

    $response->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => trans('forms::forms/messages.api.form_submitted'),
        ]);
});

test('options endpoint exposes cors metadata', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
    ]);

    $response = $this->optionsJson("/api/v1/forms/{$form->id}/submit");

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'methods' => ['GET', 'POST', 'OPTIONS'],
        ]);
});

test('options endpoint returns not found for missing form', function (): void {
    $uuid = (string) Str::uuid();

    $response = $this->optionsJson("/api/v1/forms/{$uuid}/submit");

    $response->assertStatus(404)
        ->assertJsonStructure(['error']);
});

test('schema endpoint returns validation metadata', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
    ]);

    $response = $this->getJson("/api/v1/forms/{$form->id}/schema");

    $response->assertOk()
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonStructure([
            'data' => ['formId', 'fields', 'validationRules', 'messages', 'attributes'],
        ]);
});

test('schema endpoint enforces restricted origin access like render and submit', function (): void {
    $form = Form::factory()->create([
        'restrict_public_access' => true,
        'enable_rate_limiting' => false,
        'status' => FormStatus::ACTIVE,
    ]);

    AllowedOrigin::factory()->for($form)->create([
        'origin' => 'allowed.example',
    ]);

    $response = $this
        ->withHeader('Origin', 'https://blocked.example')
        ->getJson("/api/v1/forms/{$form->id}/schema");

    $response->assertForbidden()
        ->assertJson([
            'error' => trans('forms::forms/shared.messages.error.origin_not_allowed', ['origin' => 'blocked.example']),
            'origin' => 'blocked.example',
        ]);
});

test('schema endpoint responds with error when form is missing', function (): void {
    $uuid = (string) Str::uuid();

    $response = $this->getJson("/api/v1/forms/{$uuid}/schema");

    $response->assertStatus(404)
        ->assertJson([
            'error' => trans('forms::forms/messages.api.form_not_found'),
        ]);
});

test('render endpoint merges additional data from render data registry', function (): void {
    $form = Form::factory()->create([
        'handle' => 'render-data-test',
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
    ]);

    $provider = new class implements FormRenderDataProvider
    {
        public function getData(Form $form, Request $request): array
        {
            return ['availability' => ['timezone' => 'Europe/Sofia']];
        }

        public function getTranslations(Form $form): array
        {
            return ['bookings' => ['subject' => 'Резервация']];
        }
    };

    $registry = app(FormRenderDataRegistry::class);
    $registry->register('render-data-test', $provider);

    $response = $this->withSession([])->getJson("/api/v1/forms/{$form->id}/render");

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'availability' => ['timezone' => 'Europe/Sofia'],
            'extension_translations' => ['bookings' => ['subject' => 'Резервация']],
        ]);
});

test('submit endpoint maps business exceptions to field errors via registry', function (): void {
    $handler = new class implements CustomFormHandler
    {
        public function handle(Form $form, array $data, Request $request): array
        {
            throw new BusinessException('Voucher has expired');
        }
    };

    $mapper = new class implements FormErrorMapper
    {
        public function map(Form $form, BusinessException $exception): ?array
        {
            if (str_contains($exception->getMessage(), 'Voucher')) {
                return ['submissionData.orderCode' => $exception->getMessage()];
            }

            return null;
        }
    };

    $form = Form::factory()->create([
        'handle' => 'error-mapper-test',
        'resolvement' => Resolvement::CUSTOM,
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'enable_rate_limiting' => false,
        'require_csrf' => false,
    ]);

    $handlerRegistry = app(FormHandlerRegistry::class);
    $handlerClass = get_class($handler);
    $handlerRegistry->register($form->handle, $handlerClass);
    app()->instance($handlerClass, $handler);

    $errorMapperRegistry = app(FormErrorMapperRegistry::class);
    $errorMapperRegistry->register($form->handle, $mapper);

    $response = $this->postJson(
        "/api/v1/forms/{$form->id}/submit",
        ['email' => 'test@example.com'],
        ['Origin' => 'https://example.com']
    );

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => 'Voucher has expired',
            'errors' => ['submissionData.orderCode' => 'Voucher has expired'],
        ]);

    $handlerRegistry->clear();
    app()->forgetInstance($handlerClass);
});

test('submit endpoint returns generic error when no mapper handles business exception', function (): void {
    $handler = new class implements CustomFormHandler
    {
        public function handle(Form $form, array $data, Request $request): array
        {
            throw new BusinessException('Unknown business error');
        }
    };

    $form = Form::factory()->create([
        'handle' => 'unmapped-error-test',
        'resolvement' => Resolvement::CUSTOM,
        'restrict_public_access' => false,
        'status' => FormStatus::ACTIVE,
        'enable_rate_limiting' => false,
        'require_csrf' => false,
    ]);

    $handlerRegistry = app(FormHandlerRegistry::class);
    $handlerClass = get_class($handler);
    $handlerRegistry->register($form->handle, $handlerClass);
    app()->instance($handlerClass, $handler);

    $response = $this->postJson(
        "/api/v1/forms/{$form->id}/submit",
        ['email' => 'test@example.com'],
        ['Origin' => 'https://example.com']
    );

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => 'Unknown business error',
        ]);

    expect($response->json('errors'))->toBeNull();

    $handlerRegistry->clear();
    app()->forgetInstance($handlerClass);
});
