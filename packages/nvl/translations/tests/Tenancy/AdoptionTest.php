<?php

declare(strict_types=1);

use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Translations\Tenancy\TranslationsAdoptionAdapter;

it('registers the package-owned translations adopter', function (): void {
    expect(app(TenantAdoptionRegistry::class)->all()['translations'] ?? null)->toBe(TranslationsAdoptionAdapter::class);
});
