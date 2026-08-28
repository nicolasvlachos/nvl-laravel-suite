<?php

declare(strict_types=1);

namespace WarningConsumer;

use Nvl\Auth\Models\Role;

return new class
{
    public function first(): ?Role
    {
        return Role::query()->first();
    }
};
