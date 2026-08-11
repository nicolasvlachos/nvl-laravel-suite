<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Nvl\Forms\Definitions\Tables\FormsTables;
use Nvl\Forms\Enums\FormStatus;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Enums\Resolvement;
use Nvl\Forms\Models\Form;
use Nvl\Forms\Tests\Stubs\TestFormsUser;

beforeEach(function (): void {

    $user = TestFormsUser::factory()->create();

    Gate::define('authoritative-group', fn () => true);
    Route::get('/dummy/{form}', fn () => '')->name('forms.public.show');
    app('router')->getRoutes()->refreshNameLookups();

    $this->actingAs($user);
});

/**
 * Build a valid forms API mutation payload.
 *
 * @param  array<string, mixed>  $overrides  Payload overrides
 * @return array<string, mixed> Valid mutation payload
 */
function formsApiMutationPayload(array $overrides = []): array
{
    return array_merge([
        'translations' => ['en' => ['name' => 'API Form '.Str::random(8)]],
        'handle' => 'api-form-'.Str::lower(Str::random(8)),
        'description' => 'Created through the canonical Forms API test.',
        'status' => FormStatus::ACTIVE->value,
        'resolvement' => Resolvement::ENTRIES->value,
        'type' => FormType::LANDING_PAGE->value,
        'restrictPublicAccess' => false,
        'allowMultipleRegistrations' => true,
        'dateRestricted' => false,
        'enableHoneypot' => true,
        'enableRateLimiting' => true,
        'rateLimitPerHour' => 10,
        'requireCsrf' => true,
        'allowedOrigins' => ['example.com'],
    ], $overrides);
}

test('canonical forms api routes cover crud and duplication', function (): void {
    Form::factory()->create([
        'name' => 'Index Form One',
        'handle' => 'index-form-one',
    ]);
    Form::factory()->create([
        'name' => 'Index Form Two',
        'handle' => 'index-form-two',
    ]);

    $this->getJson(route('nvl.forms.management.index', ['per_page' => 1]))
        ->assertOk()
        ->assertJsonPath('data.forms.meta.perPage', 1)
        ->assertJsonCount(1, 'data.forms.items')
        ->assertJsonMissingPath('data.forms.items.0.restrict_public_access');

    $createResponse = $this->postJson(route('nvl.forms.management.store'), formsApiMutationPayload([
        'translations' => ['en' => ['name' => 'Created API Form']],
        'handle' => 'created-api-form',
    ]));

    $createResponse
        ->assertCreated()
        ->assertJsonPath('data.name', 'Created API Form')
        ->assertJsonPath('data.handle', 'created-api-form')
        ->assertJsonMissingPath('data.restrict_public_access');

    $createdId = (string) $createResponse->json('data.id');

    $this->getJson(route('nvl.forms.management.show', $createdId))
        ->assertOk()
        ->assertJsonPath('data.form.id', $createdId)
        ->assertJsonPath('data.form.name', 'Created API Form')
        ->assertJsonMissingPath('data.form.restrict_public_access')
        ->assertJsonStructure(['data' => ['form', 'states' => ['status', 'security', 'links', 'stats']]]);

    $this->putJson(route('nvl.forms.management.update', $createdId), formsApiMutationPayload([
        'translations' => ['en' => ['name' => 'Updated API Form']],
        'handle' => 'updated-api-form',
        'allowedOrigins' => ['updated.example.com'],
        'expectedRevision' => 1,
    ]))
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated API Form')
        ->assertJsonPath('data.handle', 'updated-api-form')
        ->assertJsonPath('data.allowedOrigins.0', 'updated.example.com');

    $duplicateResponse = $this->postJson(route('nvl.forms.management.duplicate', $createdId), [
        'name' => 'Duplicated API Form',
    ]);

    $duplicateResponse
        ->assertCreated()
        ->assertJsonPath('data.name', 'Duplicated API Form');

    expect((string) $duplicateResponse->json('data.id'))->not->toBe($createdId);

    $this->deleteJson(route('nvl.forms.management.destroy', $createdId))
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    $this->assertSoftDeleted(FormsTables::Forms, ['id' => $createdId]);
});

test('suggestions endpoint returns matching forms', function (): void {
    Form::factory()->create(['name' => 'Demo Registration', 'handle' => 'demo-registration']);
    Form::factory()->create(['name' => 'Product Demo', 'handle' => 'product-demo']);
    Form::factory()->create(['name' => 'Support Request', 'handle' => 'support-request']);

    $response = $this->getJson('/api/v1/forms/suggestions?q=demo&limit=5');

    $response->assertOk()
        ->assertJson(
            fn (AssertableJson $json) => $json
                ->has('data', 2)
                ->where('data.0.label', 'Demo Registration')
                ->etc()
        );
});

test('search endpoint returns forms with metadata', function (): void {
    Form::factory()->count(2)->create(['submissions_count' => 3]);
    Form::factory()->create(['submissions_count' => 0]);

    $response = $this->getJson('/api/v1/forms/search?hasSubmissions=1');

    $response->assertOk()
        ->assertJson(
            fn (AssertableJson $json) => $json
                ->has('data.items', 2)
                ->where('data.total', 2)
                ->etc()
        );
});

test('select endpoint honours filters', function (): void {
    Form::factory()->create([
        'name' => 'Public Form',
        'handle' => 'public-form',
        'restrict_public_access' => false,
        'submissions_count' => 5,
    ]);

    Form::factory()->create([
        'name' => 'Private Form',
        'handle' => 'private-form',
        'restrict_public_access' => true,
        'submissions_count' => 10,
    ]);

    $response = $this->getJson('/api/v1/forms/select?publicOnly=1&withSubmissions=1');

    $publicForm = Form::where('handle', 'public-form')->first();
    expect($publicForm)->not->toBeNull();

    $response->assertOk()
        ->assertJson(
            fn (AssertableJson $json) => $json
                ->has('data', 1)
                ->where('data.0.id', $publicForm?->id)
                ->etc()
        );
});

test('all management discovery endpoints enforce the form policy', function (): void {
    Gate::define('manage-forms', static fn (): bool => false);

    foreach (['suggestions', 'search', 'select'] as $endpoint) {
        $this->getJson("/api/v1/forms/{$endpoint}")->assertForbidden();
    }
});
