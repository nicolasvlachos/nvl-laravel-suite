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
        Schema::create(SeoTables::Profiles, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 100)->default('default');
            $table->string('seoable_type');
            $table->string('seoable_id')
                ->comment('Owner key supporting integer, UUID, ULID, and string identifiers.');
            $table->boolean('is_indexable')->default(true);
            $table->boolean('is_followable')->default(true);
            $table->integer('max_snippet')->nullable();
            $table->string('max_image_preview', 20)->nullable()->default('large');
            $table->integer('max_video_preview')->nullable();
            $table->boolean('sitemap_included')->default(true);
            $table->decimal('sitemap_priority', 2, 1)->nullable();
            $table->string('sitemap_change_frequency', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['scope', 'seoable_type', 'seoable_id'],
                'seo_profiles_scope_owner_unique',
            );
            $table->index(
                ['seoable_type', 'seoable_id'],
                'seo_profiles_owner_index',
            );
            $table->index(
                ['scope', 'sitemap_included', 'updated_at'],
                'seo_profiles_sitemap_index',
            );
            $table->index(
                ['scope', 'status', 'updated_at'],
                'seo_profiles_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(SeoTables::Profiles);
    }
};
