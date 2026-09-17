<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Forms\Definitions\Tables\FormsTables;

return new class extends Migration
{
    /** Add canonical tenant identity to the complete Forms graph. */
    public function up(): void
    {
        foreach ([
            FormsTables::Forms,
            FormsTables::Entries,
            FormsTables::SubmissionReceipts,
            FormsTables::AllowedOrigins,
            FormsTables::Analytics,
            FormsTables::RateLimits,
            FormsTables::I18n,
        ] as $tableName) {
            if (! Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, static function (Blueprint $table): void {
                    $table->uuid('tenant_id')->nullable();
                });
            }
        }

        Schema::table(FormsTables::Forms, static function (Blueprint $table): void {
            $table->dropUnique(['handle']);
            $table->unique(['tenant_id', 'handle'], 'forms_tenant_handle_unique');
            $table->index(['tenant_id', 'status', 'id'], 'forms_tenant_status_idx');
        });
        foreach ([FormsTables::Entries, FormsTables::SubmissionReceipts, FormsTables::AllowedOrigins, FormsTables::Analytics, FormsTables::RateLimits, FormsTables::I18n] as $tableName) {
            Schema::table($tableName, static function (Blueprint $table): void {
                $table->index(['tenant_id', 'form_id'], 'forms_tenant_parent_'.substr(hash('sha256', $table->getTable()), 0, 8));
            });
        }
    }

    /** Preserve adopted ownership evidence. */
    public function down(): void {}
};
