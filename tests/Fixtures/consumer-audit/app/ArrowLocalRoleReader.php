<?php

declare(strict_types=1);

namespace Consumer;

use Closure;
use Nvl\Auth\Models\Role;

return new class
{
    public function reader(): Closure
    {
        return fn (): array => [
            $role = Role::query()->firstOrFail(),
            function () use ($role): mixed {
                return $role->permissions;
            },
        ];
    }
};
