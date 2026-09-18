<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Seo\Models\SeoProfile;
use Nvl\Seo\Models\SeoProfileTranslation;
use Nvl\Seo\Models\SeoRedirect;
use Nvl\Seo\Tests\Fixtures\TenantScenario;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;

it('activates profiles translations redirects and source identity as one graph', function (): void {
    TenantScenario::install();

    expect(Schema::hasColumn((new SeoProfile)->getTable(), 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn((new SeoProfileTranslation)->getTable(), 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn((new SeoRedirect)->getTable(), 'tenant_id'))->toBeTrue()
        ->and(app(TenantInstallationState::class)->assertUsable('seo.profiles'))->toBeNull()
        ->and(app(TenantInstallationState::class)->assertUsable('seo.redirects'))->toBeNull();
});

it('rehashes a reviewed legacy redirect and resumes from bounded checkpoints', function (): void {
    $id = (string) Str::uuid();
    DB::table((new SeoRedirect)->getTable())->insert([
        'id' => $id,
        'scope' => 'default',
        'locale' => null,
        'source_path' => '/legacy',
        'source_hash' => hash('sha256', 'legacy-global-source'),
        'target' => '/current',
        'status_code' => 301,
        'is_active' => true,
        'hit_count' => 0,
        'revision' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scenario = TenantScenario::install([
        new TenantAssignment('seo.redirects', $id, new TenantId(TenantScenario::A)),
    ]);
    $redirect = $scenario->runWithSite(
        TenantScenario::A,
        static fn () => SeoRedirect::query()->findOrFail($id),
    );

    expect($redirect->tenant_id)->toBe(TenantScenario::A)
        ->and($redirect->source_hash)->toBe(
            SeoRedirect::sourceHashForTenant(TenantScenario::A, 'default', null, '/legacy'),
        );
});
