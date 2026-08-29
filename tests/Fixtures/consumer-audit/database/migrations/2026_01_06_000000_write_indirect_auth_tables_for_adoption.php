<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

$rolesTable = 'nvl_auth_roles';

DB::table($rolesTable)
    ->where('name', 'manager')
    ->update(['display_name' => 'Manager']);

$permissionsTable = 'nvl_auth_permissions';

DB::table($permissionsTable)->insert([
    'name' => 'manage-users',
    'guard_name' => 'web',
]);
