<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

DB::table('application_users')
    ->whereIn(
        'role_id',
        DB::table('nvl_auth_roles')->select('id'),
    )
    ->update(['reviewed' => true]);
