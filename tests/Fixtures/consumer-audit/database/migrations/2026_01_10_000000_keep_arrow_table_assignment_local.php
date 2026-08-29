<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

$table = 'application_users';
$introducePackageTable = fn (): string => $table = 'nvl_auth_roles';

$introducePackageTable();

DB::table($table)
    ->whereNull('reviewed_at')
    ->update(['reviewed' => true]);

$siblingWrite = fn (): int => DB::table($table)
    ->where('active', false)
    ->delete();

$siblingWrite();
