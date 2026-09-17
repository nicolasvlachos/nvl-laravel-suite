<?php

declare(strict_types=1);

namespace App\Tenancy;

use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\PlatformOperation;

/** Authorizes only the sealed fixture's bounded provisioning and adoption operations. */
class ConsumerPlatformAccess implements PlatformAccess
{
    /** Authorize one exact fixture-owned platform operation. */
    public function authorize(PlatformOperation $operation): void
    {
        if ($operation->actorType !== 'system'
            || $operation->actorId !== 'tenancy-production-consumer'
            || ! str_starts_with($operation->purpose, 'tenancy-consumer.')) {
            throw new TenantBoundaryViolation('The tenancy consumer platform operation is denied.');
        }
    }
}
