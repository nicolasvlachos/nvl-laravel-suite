<?php

declare(strict_types=1);

namespace Nvl\Translations\Tenancy;

use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Translations\Models\TenantTranslationOverride;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationScanRun;
use Nvl\Translations\Models\TranslationUsage;

final readonly class TranslationsResourceRegistrar
{
    public function register(TenantResourceRegistry $resources, TenantAdoptionRegistry $adapters): void
    {
        foreach ([
            new TenantResourceDefinition('translations.catalog', 'translations', TranslationEntry::class, TenantResourceKind::Platform),
            new TenantResourceDefinition('translations.usages', 'translations', TranslationUsage::class, TenantResourceKind::Platform),
            new TenantResourceDefinition('translations.scans', 'translations', TranslationScanRun::class, TenantResourceKind::Platform),
            new TenantResourceDefinition('translations.overrides', 'translations', TenantTranslationOverride::class),
        ] as $resource) {
            $resources->register($resource);
        }
        $adapters->register('translations', TranslationsAdoptionAdapter::class);
    }
}
