<?php

declare(strict_types=1);

namespace App\Auth\Rbac;

use Nvl\Auth\Contracts\PermissionCatalogProvider;

/** Contributes the proof consumer's single management permission. */
final class AuthConsumerPermissionCatalog implements PermissionCatalogProvider
{
    /** @return list<string> */
    public function permissions(): array
    {
        return ['auth-consumer.manage'];
    }
}
