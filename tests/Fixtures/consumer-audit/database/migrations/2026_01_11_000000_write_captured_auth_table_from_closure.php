<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

$table = 'nvl_auth_roles';
$writeRoles = function () use ($table): int {
    return DB::table($table)
        ->where('name', 'manager')
        ->update(['display_name' => 'Manager']);
};

$writeRoles();
