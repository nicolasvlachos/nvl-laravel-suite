<?php

declare(strict_types=1);

namespace Consumer;

use Nvl\Auth\Actions\CreateRoleAction;
use Nvl\Auth\Data\RoleData;
use Nvl\Auth\Models\Role;

return new class
{
    public function describe(Role $role): array
    {
        return [
            'model' => Role::class,
            'action' => CreateRoleAction::class,
            'data' => RoleData::fromModel($role),
            'label' => 'nvl_auth_roles',
        ];
    }
};
