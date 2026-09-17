<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Content\Support\ContentConfiguration;

return new class extends Migration
{
    /** Expand Content storage with resumable tenant ownership columns and tenant-leading indexes. */
    public function up(): void
    {
        $schema = Schema::connection(ContentConfiguration::connection());
        $blocks = ContentConfiguration::table('blocks');
        $translations = ContentConfiguration::table('blocks_i18n');
        $placements = ContentConfiguration::table('placements');
        $revisions = ContentConfiguration::table('revisions');

        $schema->table($blocks, function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->dropUnique('content_blocks_scope_key_unique');
            $table->unique(['tenant_id', 'scope', 'scope_key', 'key'], 'content_blocks_tenant_scope_key_unique');
            $table->unique(['tenant_id', 'id'], 'content_blocks_tenant_id_unique');
            $table->index(['tenant_id', 'scope', 'scope_key', 'status'], 'content_blocks_tenant_scope_state_idx');
        });
        $schema->table($translations, function (Blueprint $table) use ($blocks): void {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->dropForeign(['content_block_id']);
            $table->index(['tenant_id', 'locale', 'content_block_id'], 'content_blocks_i18n_tenant_lookup_idx');
            $table->foreign(['tenant_id', 'content_block_id'], 'content_blocks_i18n_tenant_block_foreign')
                ->references(['tenant_id', 'id'])->on($blocks)->cascadeOnDelete();
        });
        $schema->table($placements, function (Blueprint $table) use ($blocks, $placements): void {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->dropForeign(['content_block_id']);
            $table->dropForeign(['parent_id']);
            $table->dropUnique('content_placements_owner_group_key_unique');
            $table->unique(['tenant_id', 'owner_type', 'owner_id', 'group', 'key'], 'content_placements_tenant_owner_key_unique');
            $table->unique(['tenant_id', 'id'], 'content_placements_tenant_id_unique');
            $table->index(['tenant_id', 'owner_type', 'owner_id', 'group', 'region', 'sort_order'], 'content_placements_tenant_composition_idx');
            $table->foreign(['tenant_id', 'content_block_id'], 'content_placements_tenant_block_foreign')
                ->references(['tenant_id', 'id'])->on($blocks)->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parent_id'], 'content_placements_tenant_parent_foreign')
                ->references(['tenant_id', 'id'])->on($placements)->restrictOnDelete();
        });
        $schema->table($revisions, function (Blueprint $table) use ($blocks): void {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->dropForeign(['content_block_id']);
            $table->index(['tenant_id', 'content_block_id', 'revision'], 'content_revisions_tenant_block_idx');
            $table->foreign(['tenant_id', 'content_block_id'], 'content_revisions_tenant_block_foreign')
                ->references(['tenant_id', 'id'])->on($blocks)->cascadeOnDelete();
        });
    }

    /** Restore the legacy unpartitioned indexes and remove optional ownership columns. */
    public function down(): void
    {
        $schema = Schema::connection(ContentConfiguration::connection());
        $blocks = ContentConfiguration::table('blocks');
        $placements = ContentConfiguration::table('placements');
        $schema->table(ContentConfiguration::table('revisions'), function (Blueprint $table) use ($blocks): void {
            $table->dropForeign('content_revisions_tenant_block_foreign');
            $table->dropIndex('content_revisions_tenant_block_idx');
            $table->dropColumn('tenant_id');
            $table->foreign('content_block_id')->references('id')->on($blocks)->cascadeOnDelete();
        });
        $schema->table($placements, function (Blueprint $table) use ($blocks, $placements): void {
            $table->dropForeign('content_placements_tenant_parent_foreign');
            $table->dropForeign('content_placements_tenant_block_foreign');
            $table->dropIndex('content_placements_tenant_composition_idx');
            $table->dropUnique('content_placements_tenant_id_unique');
            $table->dropUnique('content_placements_tenant_owner_key_unique');
            $table->unique(['owner_type', 'owner_id', 'group', 'key'], 'content_placements_owner_group_key_unique');
            $table->dropColumn('tenant_id');
            $table->foreign('content_block_id')->references('id')->on($blocks)->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on($placements)->nullOnDelete();
        });
        $schema->table(ContentConfiguration::table('blocks_i18n'), function (Blueprint $table) use ($blocks): void {
            $table->dropForeign('content_blocks_i18n_tenant_block_foreign');
            $table->dropIndex('content_blocks_i18n_tenant_lookup_idx');
            $table->dropColumn('tenant_id');
            $table->foreign('content_block_id')->references('id')->on($blocks)->cascadeOnDelete();
        });
        $schema->table(ContentConfiguration::table('blocks'), function (Blueprint $table): void {
            $table->dropIndex('content_blocks_tenant_scope_state_idx');
            $table->dropUnique('content_blocks_tenant_scope_key_unique');
            $table->dropUnique('content_blocks_tenant_id_unique');
            $table->unique(['scope', 'scope_key', 'key'], 'content_blocks_scope_key_unique');
            $table->dropColumn('tenant_id');
        });
    }
};
