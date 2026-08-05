<?php

declare(strict_types=1);

namespace App\Auth\Management\Users;

use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Restricts user assignments to the synchronized consumer-owned RBAC catalog.
 */
final readonly class AccessAssignmentValidator
{
    /**
     * Validate role and permission handles against the web-guard catalog.
     *
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function validate(array $roles, array $permissions): void
    {
        $unknownRoles = $this->unknownRoles($roles);
        $unknownPermissions = $this->unknownPermissions($permissions);

        if ($unknownRoles === [] && $unknownPermissions === []) {
            return;
        }

        throw ValidationException::withMessages([
            'roles' => $unknownRoles === []
                ? []
                : ['Unknown roles: '.implode(', ', $unknownRoles).'.'],
            'permissions' => $unknownPermissions === []
                ? []
                : ['Unknown permissions: '.implode(', ', $unknownPermissions).'.'],
        ]);
    }

    /** @param list<string> $roles @return list<string> */
    private function unknownRoles(array $roles): array
    {
        $known = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $roles)
            ->pluck('name')
            ->all();

        return array_values(array_diff($roles, $known));
    }

    /** @param list<string> $permissions @return list<string> */
    private function unknownPermissions(array $permissions): array
    {
        $known = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissions)
            ->pluck('name')
            ->all();

        return array_values(array_diff($permissions, $known));
    }
}
