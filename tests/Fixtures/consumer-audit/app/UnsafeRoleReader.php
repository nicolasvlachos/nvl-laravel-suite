<?php

declare(strict_types=1);

namespace Consumer;

use Nvl\Auth\Models\Role as AuthRole;

return new class
{
    public function count(): int
    {
        return AuthRole::query()->count();
    }
};
