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
        Schema::create(SeoTables::Redirects, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 100)->default('default');
            $table->string('locale', 35)->nullable();
            $table->text('source_path');
            $table->char('source_hash', 64);
            $table->text('target');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedBigInteger('hit_count')->default(0);
            $table->timestampTz('last_hit_at')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->json('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique('source_hash', 'seo_redirects_source_hash_unique');
            $table->index(
                ['scope', 'locale', 'is_active', 'expires_at'],
                'seo_redirects_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(SeoTables::Redirects);
    }
};
