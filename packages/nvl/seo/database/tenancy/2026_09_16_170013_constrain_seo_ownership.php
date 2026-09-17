<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Seo\Definitions\Tables\SeoTables;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([SeoTables::Profiles, SeoTables::I18n, SeoTables::Redirects, SeoTables::RedirectLocks] as $table) {
            Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->uuid('tenant_id')->nullable(false)->change());
        }
    }

    public function down(): void
    {
        foreach ([SeoTables::Profiles, SeoTables::I18n, SeoTables::Redirects, SeoTables::RedirectLocks] as $table) {
            Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->uuid('tenant_id')->nullable()->change());
        }
    }
};
