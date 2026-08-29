<?php

declare(strict_types=1);

namespace Consumer;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Auth\Actions\Rbac\UpdateRoleAction;
use Nvl\Auth\Data\Mutations\UpdateRoleData;
use Nvl\Auth\Models\Role;
use Nvl\Pages\Models\Page;

return new class
{
    public function identity(Role $role): mixed
    {
        return $role->getRouteKey();
    }

    public function mutate(
        Role $role,
        Authenticatable $actor,
        UpdateRoleAction $action,
        UpdateRoleData $data,
    ): Role {
        $updated = $action->execute($actor, $role, $data);
        $updated->getRouteKey();

        return $updated;
    }

    public function ownerRelations(Page $page): void
    {
        $page->contentPlacements();
        $page->metafields();
        $page->seoProfile();
    }
};
