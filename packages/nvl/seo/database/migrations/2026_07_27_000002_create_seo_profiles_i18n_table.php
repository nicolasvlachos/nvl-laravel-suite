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
        Schema::create(SeoTables::I18n, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('seo_profile_id');
            $table->string('scope', 100);
            $table->string('locale', 35);
            $table->text('path')->nullable();
            $table->char('path_hash', 64)->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('canonical_url')->nullable();
            $table->text('image_url')->nullable();
            $table->string('image_reference')->nullable();
            $table->string('image_alt')->nullable();
            $table->string('open_graph_title')->nullable();
            $table->text('open_graph_description')->nullable();
            $table->string('twitter_title')->nullable();
            $table->text('twitter_description')->nullable();
            $table->string('twitter_card', 30)->nullable();
            $table->json('structured_data')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['seo_profile_id', 'locale'],
                'seo_profiles_i18n_owner_locale_unique',
            );
            $table->unique(
                ['scope', 'locale', 'path_hash'],
                'seo_profiles_i18n_route_unique',
            );
            $table->index(
                ['scope', 'locale', 'updated_at'],
                'seo_profiles_i18n_sitemap_index',
            );

            $table->foreign('seo_profile_id')
                ->references('id')
                ->on(SeoTables::Profiles)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(SeoTables::I18n);
    }
};
