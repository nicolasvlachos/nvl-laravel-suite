<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('translation_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('identity_hash', 64)->unique();
            $table->string('scope_type', 32);
            $table->string('scope_name', 120);
            $table->string('locale', 35);
            $table->string('format', 16);
            $table->string('group')->default('*');
            $table->text('key');
            $table->text('value')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->boolean('is_missing')->default(false);
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('sync_status', 32)->default('synchronized');
            $table->json('conflict_metadata')->nullable();
            $table->timestampTz('last_imported_at')->nullable();
            $table->timestampTz('last_exported_at')->nullable();
            $table->timestampsTz();

            $table->index(['scope_type', 'scope_name'], 'translation_entries_scope_index');
            $table->index(['locale', 'format'], 'translation_entries_locale_format_index');
            $table->index(['group'], 'translation_entries_group_index');
            $table->index(['is_missing'], 'translation_entries_missing_index');
            $table->index(['last_imported_at'], 'translation_entries_last_imported_index');
            $table->index(
                ['sync_status', 'scope_type', 'scope_name'],
                'translation_entries_status_index',
            );
            $table->index(['updated_at', 'id'], 'translation_entries_updated_index');
            $table->index(
                ['scope_type', 'scope_name', 'locale', 'format', 'is_missing'],
                'translation_entries_export_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translation_entries');
    }
};
