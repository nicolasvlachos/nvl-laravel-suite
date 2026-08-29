<?php

declare(strict_types=1);

namespace Consumer;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Auth\Actions\Rbac\UpdateRoleAction;
use Nvl\Auth\Data\Mutations\UpdateRoleData;
use Nvl\Auth\Models\Role;

final readonly class ConsumerBoundaryProbe
{
    public function __construct(private UpdateRoleAction $action) {}

    public function lazyRelation(Role $role): mixed
    {
        return $role->permissions;
    }

    public function closureRelation(Role $role): int
    {
        return (static function () use ($role): int {
            return $role->permissions()->count();
        })();
    }

    public function propertyActionRelation(
        Authenticatable $actor,
        Role $role,
        UpdateRoleData $data,
    ): int {
        $updated = $this->action->execute($actor, $role, $data);

        return $updated->permissions()->count();
    }
}
