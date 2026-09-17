<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Translations\Definitions\Tables\TranslationsTables;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(TranslationsTables::TenantOverrides)) {
            return;
        }
        Schema::create(TranslationsTables::TenantOverrides, static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('key', 191);
            $table->string('locale', 35);
            $table->text('value');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'key', 'locale'], 'tenant_translation_override_identity_unique');
            $table->index(['tenant_id', 'updated_at'], 'tenant_translation_override_recent_idx');
        });
    }

    public function down(): void {}
};
