<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Pages\Definitions\Tables\PagesTables;
use Nvl\Pages\Support\PagesConfiguration;

return new class extends Migration
{
    /** Add resumable tenant ownership and tenant-local Page identities. */
    public function up(): void
    {
        $schema = Schema::connection(PagesConfiguration::connection());
        $pages = PagesConfiguration::table(PagesTables::Pages, PagesTables::Pages);
        $translations = PagesConfiguration::table(PagesTables::I18n, PagesTables::I18n);
        $locks = PagesConfiguration::table(PagesTables::TreeLocks, PagesTables::TreeLocks);

        $schema->table($pages, function (Blueprint $table) use ($pages): void {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->dropUnique(['key']);
            $table->dropUnique('pages_site_sibling_slug_unique');
            $table->dropUnique('pages_site_path_hash_unique');
            $table->dropForeign(['parent_id']);
            $table->unique(['tenant_id', 'id'], 'pages_tenant_id_unique');
            $table->unique(['tenant_id', 'key'], 'pages_tenant_key_unique');
            $table->unique(['tenant_id', 'site', 'parent_key', 'slug'], 'pages_tenant_site_sibling_unique');
            $table->unique(['tenant_id', 'site', 'path_hash'], 'pages_tenant_site_path_unique');
            $table->index(['tenant_id', 'site', 'status', 'published_at', 'expires_at'], 'pages_tenant_public_lookup_idx');
            $table->foreign(['tenant_id', 'parent_id'], 'pages_tenant_parent_foreign')->references(['tenant_id', 'id'])->on($pages)->restrictOnDelete();
        });
        $schema->table($translations, function (Blueprint $table) use ($pages): void {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->dropForeign(['page_id']);
            $table->index(['tenant_id', 'locale', 'page_id'], 'pages_i18n_tenant_lookup_idx');
            $table->foreign(['tenant_id', 'page_id'], 'pages_i18n_tenant_page_foreign')->references(['tenant_id', 'id'])->on($pages)->cascadeOnDelete();
        });
        $schema->table($locks, function (Blueprint $table): void {
            $table->dropPrimary();
            $table->uuid('tenant_id')->nullable()->first();
            $table->unique(['tenant_id', 'site'], 'page_tree_locks_tenant_site_unique');
        });
    }

    /** Restore the legacy global Page identity and lock schema. */
    public function down(): void
    {
        $schema = Schema::connection(PagesConfiguration::connection());
        $pages = PagesConfiguration::table(PagesTables::Pages, PagesTables::Pages);
        $translations = PagesConfiguration::table(PagesTables::I18n, PagesTables::I18n);
        $locks = PagesConfiguration::table(PagesTables::TreeLocks, PagesTables::TreeLocks);
        $schema->table($locks, function (Blueprint $table): void {
            $table->dropUnique('page_tree_locks_tenant_site_unique');
            $table->dropColumn('tenant_id');
            $table->primary('site');
        });
        $schema->table($translations, function (Blueprint $table) use ($pages): void {
            $table->dropForeign('pages_i18n_tenant_page_foreign');
            $table->dropIndex('pages_i18n_tenant_lookup_idx');
            $table->dropColumn('tenant_id');
            $table->foreign('page_id')->references('id')->on($pages)->cascadeOnDelete();
        });
        $schema->table($pages, function (Blueprint $table) use ($pages): void {
            $table->dropForeign('pages_tenant_parent_foreign');
            $table->dropIndex('pages_tenant_public_lookup_idx');
            $table->dropUnique('pages_tenant_site_path_unique');
            $table->dropUnique('pages_tenant_site_sibling_unique');
            $table->dropUnique('pages_tenant_key_unique');
            $table->dropUnique('pages_tenant_id_unique');
            $table->dropColumn('tenant_id');
            $table->unique('key');
            $table->unique(['site', 'parent_key', 'slug'], 'pages_site_sibling_slug_unique');
            $table->unique(['site', 'path_hash'], 'pages_site_path_hash_unique');
            $table->foreign('parent_id')->references('id')->on($pages)->restrictOnDelete();
        });
    }
};
