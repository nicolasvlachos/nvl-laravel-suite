<?php

declare(strict_types=1);

namespace Nvl\ConsumerPackage;

use Nvl\Auth\Models\Role;

return new class
{
    public function first(): ?Role
    {
        return Role::first();
    }
};
