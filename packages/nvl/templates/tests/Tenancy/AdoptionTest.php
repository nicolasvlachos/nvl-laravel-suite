<?php

declare(strict_types=1);

use Nvl\Templates\Tenancy\TemplatesAdoptionAdapter;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

it('registers the template graph adopter', function (): void {
    expect(app(TenantAdoptionRegistry::class)->all()['templates'] ?? null)->toBe(TemplatesAdoptionAdapter::class);
});
