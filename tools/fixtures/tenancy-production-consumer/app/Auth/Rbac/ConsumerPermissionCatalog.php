<?php

declare(strict_types=1);

namespace App\Auth\Rbac;

use Nvl\Auth\Contracts\PermissionCatalogProvider;

/** Contributes the bounded permission vocabulary used by the full proof. */
final class ConsumerPermissionCatalog implements PermissionCatalogProvider
{
    /** @return list<string> */
    public function permissions(): array
    {
        return ['tenancy-consumer.manage', 'nvl-auth.rbac.view'];
    }
}
