<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB as Database;
use Illuminate\Support\Facades\Schema as DatabaseSchema;

Database::table('nvl_auth_roles')->count();
Database::table('nvl_auth_permissions')->insert([
    'name' => 'manage-suite',
    'guard_name' => 'web',
]);
DatabaseSchema::CREATE('nvl_auth_permissions', function (Blueprint $table): void {
    $table->uuid('id')->primary();
});
