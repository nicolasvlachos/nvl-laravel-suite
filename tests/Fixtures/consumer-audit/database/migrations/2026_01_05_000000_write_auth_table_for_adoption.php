<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

DB::table('nvl_auth_roles')->where('name', 'manager')->update(['display_name' => 'Manager']);
DB::table('nvl_auth_roles')->insert(['name' => 'editor', 'guard_name' => 'web']);
DB::table('nvl_auth_roles')->where('name', 'obsolete')->delete();
