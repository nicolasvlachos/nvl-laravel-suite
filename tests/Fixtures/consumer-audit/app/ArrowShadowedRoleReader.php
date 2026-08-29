<?php

declare(strict_types=1);

namespace Consumer;

use Closure;
use Nvl\Auth\Models\Role;

return new class
{
    public function reader(Role $role): Closure
    {
        return fn ($role): Closure => function () use ($role): mixed {
            return $role->permissions;
        };
    }
};
