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
        Schema::table(SeoTables::Profiles, function (Blueprint $table): void {
            $table->dropIndex('seo_profiles_sitemap_index');
            $table->dropIndex('seo_profiles_status_index');
            $table->index(
                ['scope', 'status', 'is_indexable', 'sitemap_included', 'id'],
                'seo_profiles_sitemap_scan_index',
            );
        });
        Schema::table(SeoTables::I18n, function (Blueprint $table): void {
            $table->dropIndex('seo_profiles_i18n_sitemap_index');
        });
        Schema::table(SeoTables::Redirects, function (Blueprint $table): void {
            $table->dropIndex('seo_redirects_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table(SeoTables::Profiles, function (Blueprint $table): void {
            $table->dropIndex('seo_profiles_sitemap_scan_index');
            $table->index(
                ['scope', 'sitemap_included', 'updated_at'],
                'seo_profiles_sitemap_index',
            );
            $table->index(
                ['scope', 'status', 'updated_at'],
                'seo_profiles_status_index',
            );
        });
        Schema::table(SeoTables::I18n, function (Blueprint $table): void {
            $table->index(
                ['scope', 'locale', 'updated_at'],
                'seo_profiles_i18n_sitemap_index',
            );
        });
        Schema::table(SeoTables::Redirects, function (Blueprint $table): void {
            $table->index(
                ['scope', 'locale', 'is_active', 'expires_at'],
                'seo_redirects_lookup_index',
            );
        });
    }
};
