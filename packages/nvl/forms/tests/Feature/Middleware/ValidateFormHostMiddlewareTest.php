<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Nvl\Forms\Contracts\FormRateLimiter;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;

test('validate form host allows unrestricted forms without origin enforcement', function (): void {
    Route::middleware('validate-form-host')->get('/testing/forms/allow/{form}', fn () => response()->json(['ok' => true]));

    $form = Form::factory()->create([
        'restrict_public_access' => false,
        'enable_rate_limiting' => false,
    ]);

    $response = $this->getJson("/testing/forms/allow/{$form->id}");

    $response->assertOk()->assertJson(['ok' => true]);
});

test('validate form host permits allowed origins and appends cors headers', function (): void {
    Route::middleware('validate-form-host')->get('/testing/forms/origin/{form}', fn () => response()->json(['ok' => true]));

    $form = Form::factory()->create([
        'restrict_public_access' => true,
        'enable_rate_limiting' => false,
    ]);

    AllowedOrigin::factory()->for($form)->create([
        'origin' => 'allowed.test',
    ]);

    $response = $this->withHeaders([
        'Origin' => 'https://allowed.test',
    ])->get("/testing/forms/origin/{$form->id}");

    $response->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://allowed.test')
        ->assertHeader('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Form-Origin, X-Forms-Public-Token, Idempotency-Key')
        ->assertHeader('Access-Control-Max-Age', '600')
        ->assertJson(['ok' => true]);
});

test('validate form host rejects unapproved origins when access is restricted', function (): void {
    Route::middleware('validate-form-host')->get('/testing/forms/reject/{form}', fn () => response()->json(['ok' => true]));

    $form = Form::factory()->create([
        'restrict_public_access' => true,
        'enable_rate_limiting' => false,
    ]);

    AllowedOrigin::factory()->for($form)->create([
        'origin' => 'allowed.test',
    ]);

    $response = $this->withHeaders([
        'Origin' => 'https://blocked.test',
    ])->getJson("/testing/forms/reject/{$form->id}");

    $response->assertForbidden()
        ->assertJson([
            'error' => trans('forms::forms/shared.messages.error.origin_not_allowed', ['origin' => 'blocked.test']),
            'origin' => 'blocked.test',
        ]);
});

test('validate form host blocks rate limited ips with dynamic retry timing', function (): void {
    Route::middleware('validate-form-host')->get('/testing/forms/rate-limited/{form}', fn () => response()->json(['ok' => true]));

    $form = Form::factory()->create([
        'restrict_public_access' => true,
        'enable_rate_limiting' => true,
        'rate_limit_per_hour' => 1,
    ]);
    AllowedOrigin::factory()->for($form)->create([
        'origin' => 'rate-limited.test',
    ]);

    $this->mock(FormRateLimiter::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getRateLimitStatus')->once()->andReturn([
            'enabled' => true,
            'remaining' => 0,
            'reset_at' => null,
            'is_blocked' => true,
            'blocked_until' => now()->addMinutes(12),
            'retry_after' => 720,
            'violation_count' => 1,
        ]);
    });

    $response = $this->withHeader('Origin', 'https://rate-limited.test')
        ->getJson("/testing/forms/rate-limited/{$form->id}");

    $response->assertStatus(429)
        ->assertJson([
            'error' => trans('forms::forms/shared.messages.error.rate_limit_exceeded'),
            'retry_after' => 720,
        ]);
});

test('validate form host does not trust spoofable iframe request headers', function (): void {
    Route::middleware('validate-form-host')->get('/testing/forms/iframe/{form}', fn () => response()->json(['ok' => true]));

    $form = Form::factory()->create([
        'type' => FormType::IFRAME,
        'restrict_public_access' => false,
    ]);

    $response = $this->getJson("/testing/forms/iframe/{$form->id}");

    $response->assertOk()
        ->assertHeaderMissing('X-Frame-Options')
        ->assertJson(['ok' => true]);
});

test('validate form host allows inertia subrequests for iframe forms', function (): void {
    Route::middleware('validate-form-host')->post('/testing/forms/iframe-inertia/{form}', fn () => redirect('/testing/forms/ok'));

    $form = Form::factory()->create([
        'type' => FormType::IFRAME,
        'restrict_public_access' => false,
    ]);

    $response = $this
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Sec-Fetch-Dest' => 'empty',
        ])
        ->post("/testing/forms/iframe-inertia/{$form->id}");

    $response->assertRedirect('/testing/forms/ok');
});

test('validate form host allows json iframe submissions after origin policy checks', function (): void {
    Route::middleware('validate-form-host')->post('/testing/forms/iframe-json/{form}', fn () => response()->json(['ok' => true]));

    $form = Form::factory()->create([
        'type' => FormType::IFRAME,
        'restrict_public_access' => false,
    ]);

    $response = $this
        ->withHeaders([
            'Sec-Fetch-Dest' => 'empty',
        ])
        ->postJson("/testing/forms/iframe-json/{$form->id}");

    $response->assertOk()->assertJson(['ok' => true]);
});

test('validate form host allows iframe form html navigations when fetch metadata is unavailable', function (): void {
    Route::middleware('validate-form-host')->get('/testing/forms/iframe-html/{form}', fn () => response('ok'));

    $form = Form::factory()->create([
        'type' => FormType::IFRAME,
        'restrict_public_access' => false,
    ]);

    $response = $this->get("/testing/forms/iframe-html/{$form->id}");

    $response->assertOk()->assertSee('ok');
});

test('validate form host leaves embedding enforcement to csp when fetch metadata says document', function (): void {
    Route::middleware('validate-form-host')->get('/testing/forms/iframe-html-detect/{form}', fn () => response('ok'));

    $form = Form::factory()->create([
        'type' => FormType::IFRAME,
        'restrict_public_access' => false,
    ]);

    $response = $this->withHeaders([
        'Sec-Fetch-Dest' => 'document',
    ])->get("/testing/forms/iframe-html-detect/{$form->id}");

    $response->assertOk()->assertSee('ok');
});

test('validate form host applies configured cors policy to real preflight requests', function (): void {
    Route::middleware('validate-form-host')->options('/testing/forms/preflight/{form}', fn () => response()->noContent());

    $form = Form::factory()->create([
        'restrict_public_access' => true,
        'enable_rate_limiting' => false,
        'cors_settings' => [
            'policy' => 'custom',
            'allowCredentials' => false,
            'maxAge' => 1200,
            'allowedMethods' => ['POST', 'OPTIONS'],
            'allowedHeaders' => ['Content-Type', 'Idempotency-Key'],
        ],
    ]);

    AllowedOrigin::factory()->for($form)->create([
        'origin' => 'embed.test',
    ]);

    $response = $this->withHeaders([
        'Origin' => 'https://embed.test',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'content-type, idempotency-key',
    ])->options("/testing/forms/preflight/{$form->id}");

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://embed.test')
        ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->assertHeader('Access-Control-Allow-Headers', 'Content-Type, Idempotency-Key')
        ->assertHeader('Access-Control-Max-Age', '1200')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
});
