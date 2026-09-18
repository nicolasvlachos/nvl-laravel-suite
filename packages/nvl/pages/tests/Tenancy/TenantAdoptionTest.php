<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Pages\Models\Page;
use Nvl\Pages\Models\PageTranslation;
use Nvl\Pages\Support\PagePath;
use Nvl\Pages\Tests\Fixtures\TenantScenario;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;

it('activates Page roots translations and concrete tree ownership together', function (): void {
    TenantScenario::install();

    expect(Schema::hasColumn((new Page)->getTable(), 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn((new PageTranslation)->getTable(), 'tenant_id'))->toBeTrue()
        ->and(app(TenantInstallationState::class)->assertUsable('pages.pages'))->toBeNull()
        ->and(app(TenantInstallationState::class)->assertUsable('pages.translations'))->toBeNull();
});

it('maps a current-schema Page and derives its lock identity before constraints', function (): void {
    $id = (string) Str::uuid();
    DB::table((new Page)->getTable())->insert([
        'id' => $id,
        'parent_id' => null,
        'parent_key' => '__root__',
        'key' => 'legacy.home',
        'site' => 'default',
        'slug' => 'legacy',
        'path' => 'legacy',
        'path_hash' => PagePath::hash('default', 'legacy'),
        'kind' => 'static',
        'status' => 'draft',
        'position' => 0,
        'is_navigable' => true,
        'sitemap_included' => true,
        'revision' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scenario = TenantScenario::install([
        new TenantAssignment('pages.pages', $id, new TenantId(TenantScenario::A)),
    ]);
    $page = $scenario->runWithSite(TenantScenario::A, static fn () => Page::query()->findOrFail($id));

    expect($page->tenant_id)->toBe(TenantScenario::A)
        ->and(DB::table((string) config('pages.tables.page_tree_locks'))
            ->where('tenant_id', TenantScenario::A)
            ->where('site', 'default')
            ->exists())->toBeTrue();
});
