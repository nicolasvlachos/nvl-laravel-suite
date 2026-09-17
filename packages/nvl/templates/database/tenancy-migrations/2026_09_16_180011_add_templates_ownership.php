<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Support\TemplatesConfiguration;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(TemplatesConfiguration::connection());
        $tables = array_map(TemplatesConfiguration::table(...), [TemplatesTables::Templates, TemplatesTables::I18n, TemplatesTables::Versions, TemplatesTables::Assignments, TemplatesTables::Renders]);
        foreach ($tables as $tableName) {
            if (! $schema->hasColumn($tableName, 'tenant_id')) {
                $schema->table($tableName, static fn (Blueprint $table) => $table->uuid('tenant_id')->nullable());
            }
        }
        foreach ([$tables[0], $tables[1]] as $tableName) {
            if (! $schema->hasColumn($tableName, 'ownership_key')) {
                $schema->table($tableName, static fn (Blueprint $table) => $table->string('ownership_key', 191)->default('platform'));
            }
        }
        $schema->table($tables[0], static function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['ownership_key', 'key'], 'templates_owner_key_unique');
        });
        $schema->table($tables[3], static function (Blueprint $table): void {
            $table->dropUnique('template_assignments_owner_profile_unique');
            $table->unique(['tenant_id', 'owner_type', 'owner_id', 'profile'], 'template_assignments_tenant_owner_unique');
        });
        $schema->table($tables[4], static function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->unique(['tenant_id', 'idempotency_key'], 'template_renders_tenant_idempotency_unique');
        });
        $schema->table($tables[2], static function (Blueprint $table): void {
            $table->uuid('catalog_grant_id')->nullable();
            $table->uuid('catalog_source_version_id')->nullable();
            $table->unsignedBigInteger('catalog_source_revision')->nullable();
            $table->string('catalog_source_hash', 64)->nullable();
            $table->string('catalog_import_key', 191)->nullable();
            $table->unique(['tenant_id', 'catalog_import_key'], 'template_versions_tenant_import_unique');
        });
        foreach ($tables as $tableName) {
            $schema->table($tableName, static fn (Blueprint $table) => $table->index(['tenant_id', 'id']));
        }
    }

    public function down(): void {}
};
