<?php

declare(strict_types=1);

use Nvl\Forms\Tenancy\FormsAdoptionAdapter;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

it('registers the canonical forms graph adopter', function (): void {
    expect(app(TenantAdoptionRegistry::class)->all()['forms'] ?? null)->toBe(FormsAdoptionAdapter::class);
});
