<?php

declare(strict_types=1);

namespace Consumer;

use Nvl\Auth\Models\Role;

return new class
{
    public function countPermissions(Role $role): int
    {
        return $role->permissions()->count();
    }

    public function findManager(Role $role): ?Role
    {
        return $role->whereName('manager')->first();
    }
};
