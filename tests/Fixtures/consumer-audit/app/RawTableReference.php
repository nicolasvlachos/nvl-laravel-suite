<?php

declare(strict_types=1);

namespace Consumer;

use Illuminate\Support\Facades\DB;

return new class
{
    public function count(): int
    {
        return DB::table('nvl_auth_roles')->count();
    }
};
