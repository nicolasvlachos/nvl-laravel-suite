<?php

declare(strict_types=1);

namespace Consumer;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Auth\Actions\Rbac\UpdateRoleAction;
use Nvl\Auth\Data\Mutations\UpdateRoleData;
use Nvl\Auth\Models\Role;

return new class
{
    public function countPermissions(
        Role $role,
        Authenticatable $actor,
        UpdateRoleAction $action,
        UpdateRoleData $data,
    ): int {
        $updated = $action->execute($actor, $role, $data);

        return $updated->permissions()->count();
    }
};
