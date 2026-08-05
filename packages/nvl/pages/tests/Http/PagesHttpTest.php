<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Pages\Contracts\PageAuthorization;
use Nvl\Pages\Tests\Fixtures\RecordingPageAuthorization;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pageHttpPayload(string $key, string $slug, array $overrides = []): array
{
    return array_replace_recursive([
        'key' => $key,
        'slug' => $slug,
        'status' => 'published',
        'translations' => [
            'en' => [
                'title' => ucfirst($slug),
                'navigationLabel' => ucfirst($slug),
            ],
        ],
    ], $overrides);
}

it('keeps public transport endpoints disjoint from valid page paths', function (): void {
    expect(app(PageAuthorization::class))->toBeInstanceOf(RecordingPageAuthorization::class)
        ->and(Route::getRoutes()->getByName('nvl.pages.public.navigation')?->uri())
        ->toBe('api/v1/pages/_navigation')
        ->and(Route::getRoutes()->getByName('nvl.pages.management.index')?->uri())
        ->toBe('api/v1/pages/_manage');

    $this->postJson(
        '/api/v1/pages/_manage',
        pageHttpPayload('pages.navigation', 'navigation'),
    )->assertCreated();
    $this->postJson(
        '/api/v1/pages/_manage',
        pageHttpPayload('pages.manage', 'manage'),
    )->assertCreated();

    $this->getJson('/api/v1/pages/navigation?locale=en')
        ->assertSuccessful()
        ->assertJsonPath('data.page.path', 'navigation');
    $this->getJson('/api/v1/pages/manage?locale=en')
        ->assertSuccessful()
        ->assertJsonPath('data.page.path', 'manage');
    $this->getJson('/api/v1/pages/_navigation?locale=en')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data.items');
});

it('rejects management payloads that exceed portable persistence bounds', function (
    array $overrides,
): void {
    $this->postJson(
        '/api/v1/pages/_manage',
        pageHttpPayload('pages.unsafe-'.md5(serialize($overrides)), 'unsafe', $overrides),
    )->assertUnprocessable();
})->with([
    'unknown translation field' => [[
        'translations' => ['en' => ['unexpected' => 'value']],
    ]],
    'oversized summary' => [[
        'translations' => ['en' => ['summary' => str_repeat('x', 2_001)]],
    ]],
    'out-of-range position' => [['position' => 2_147_483_648]],
]);
