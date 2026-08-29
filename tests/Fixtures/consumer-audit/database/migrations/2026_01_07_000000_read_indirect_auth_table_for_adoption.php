<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

$rolesTable = 'nvl_auth_roles';

DB::table($rolesTable)
    ->select('id')
    ->whereNotNull('name')
    ->get();
