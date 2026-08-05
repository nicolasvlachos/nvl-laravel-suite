<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Models\Form;

test('form available middleware returns not found when identifier is missing', function (): void {
    Route::middleware('form-available')->get('/testing/forms/availability/not-found/{form}', fn () => response()->json(['ok' => true]));

    $response = $this->getJson('/testing/forms/availability/not-found/00000000-0000-0000-0000-000000000000');

    $response->assertNotFound()
        ->assertJson([
            'success' => false,
            'error' => trans('forms::forms/messages.api.form_not_found'),
        ]);
});

test('form available middleware forbids unavailable forms for api consumers', function (): void {
    Route::middleware('form-available')->get('/testing/forms/availability/api/{form}', fn () => response()->json(['ok' => true]));

    Carbon::setTestNow(Carbon::parse('2024-05-01 10:00:00'));

    $form = Form::factory()->create([
        'date_restricted' => true,
        'status' => FormStatus::ACTIVE,
        'available_from' => now()->addHour(),
        'available_until' => now()->addHours(2),
    ]);

    $response = $this->getJson("/testing/forms/availability/api/{$form->id}");

    $response->assertForbidden()
        ->assertJson([
            'success' => false,
            'error' => trans('forms::forms/messages.api.form_unavailable'),
        ]);

    Carbon::setTestNow();
});

test('form available middleware redirects web requests when form unavailable', function (): void {
    Route::middleware(['web', 'form-available'])->get('/testing/forms/availability/web/{form}', fn () => response()->json(['ok' => true]));

    $form = Form::factory()->create([
        'date_restricted' => false,
        'status' => FormStatus::DRAFT,
    ]);

    $response = $this->from('/previous')
        ->get("/testing/forms/availability/web/{$form->id}");

    $response->assertRedirect('/previous')
        ->assertSessionHasErrors('error');
});

test('form available middleware allows rendering when form is available', function (): void {
    Route::middleware('form-available')->get('/testing/forms/availability/pass/{form}', fn () => response()->json(['ok' => true]));

    $form = Form::factory()->create([
        'date_restricted' => false,
        'status' => FormStatus::ACTIVE,
    ]);

    $response = $this->getJson("/testing/forms/availability/pass/{$form->id}");

    $response->assertOk()->assertJson(['ok' => true]);
});
