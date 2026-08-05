<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Content\Support\ContentConfiguration;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(ContentConfiguration::connection());
        $tableName = ContentConfiguration::table('placements');

        if ($schema->hasTable($tableName)) {
            throw new LogicException(
                "Content placements table [{$tableName}] already exists; ".
                'disable content.migrations.enabled during controlled schema adoption.',
            );
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id');
            $table->primary('id');
            $table->foreignUuid('content_block_id')
                ->constrained(ContentConfiguration::table('blocks'))
                ->cascadeOnDelete();
            $table->string('owner_type', 100);
            $table->string('owner_id', 191);
            $table->string('group', 100)->default('default');
            $table->string('key', 191);
            $table->foreignUuid('parent_id')
                ->nullable()
                ->constrained(ContentConfiguration::table('placements'))
                ->nullOnDelete();
            $table->string('region', 100)->default('main');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('overrides')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(
                ['owner_type', 'owner_id', 'group', 'key'],
                'content_placements_owner_group_key_unique',
            );
            $table->index(
                ['owner_type', 'owner_id', 'group', 'region', 'sort_order', 'id'],
                'content_placements_group_composition_idx',
            );
            $table->index(['parent_id', 'sort_order', 'id'], 'content_placements_parent_idx');
            $table->index(['content_block_id', 'is_visible'], 'content_placements_block_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(ContentConfiguration::connection())
            ->dropIfExists(ContentConfiguration::table('placements'));
    }
};
