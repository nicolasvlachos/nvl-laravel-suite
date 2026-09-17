<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Pages\Definitions\Tables\PagesTables;
use Nvl\Pages\Support\PagesConfiguration;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(PagesConfiguration::connection());
        foreach ([PagesTables::Pages, PagesTables::I18n, PagesTables::TreeLocks] as $name) {
            $table = PagesConfiguration::table($name, $name);
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->uuid('tenant_id')->nullable(false)->change());
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(PagesConfiguration::connection());
        foreach ([PagesTables::Pages, PagesTables::I18n, PagesTables::TreeLocks] as $name) {
            $table = PagesConfiguration::table($name, $name);
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->uuid('tenant_id')->nullable()->change());
        }
    }
};
