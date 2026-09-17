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
        $tableName = TemplatesConfiguration::table(TemplatesTables::TenantGrants);
        if ($schema->hasTable($tableName)) {
            return;
        }
        $schema->create($tableName, static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('template_version_id');
            $table->uuid('recipient_tenant_id');
            $table->unsignedBigInteger('source_revision');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['template_version_id', 'recipient_tenant_id'], 'template_grants_version_recipient_unique');
            $table->index(['recipient_tenant_id', 'revoked_at'], 'template_grants_recipient_active_idx');
        });
        $lockTable = TemplatesConfiguration::table(TemplatesTables::TenantGrantLocks);
        if (! $schema->hasTable($lockTable)) {
            $schema->create($lockTable, static function (Blueprint $table): void {
                $table->uuid('recipient_tenant_id');
                $table->uuid('template_version_id');
                $table->timestampsTz();
                $table->primary(['recipient_tenant_id', 'template_version_id'], 'template_grant_lock_primary');
            });
        }
    }

    public function down(): void {}
};
