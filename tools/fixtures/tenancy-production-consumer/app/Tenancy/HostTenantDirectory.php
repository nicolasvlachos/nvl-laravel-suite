<?php

declare(strict_types=1);

namespace App\Tenancy;

use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Resolves the two explicit host tenants used without NVL Auth. */
final class HostTenantDirectory implements TenantDirectory
{
    public const string TENANT_A = '018f1000-0000-7000-8000-000000000001';

    public const string TENANT_B = '018f1000-0000-7000-8000-000000000002';

    /** Resolve one allowlisted active host tenant. */
    public function find(TenantId $tenant): TenantDescriptor
    {
        if (! in_array($tenant->value, [self::TENANT_A, self::TENANT_B], true)) {
            throw new TenantNotFound;
        }

        return new TenantDescriptor($tenant, TenantStatus::Active);
    }
}
