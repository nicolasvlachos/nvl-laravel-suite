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
        $tableName = ContentConfiguration::table('blocks');

        if ($schema->hasTable($tableName)) {
            throw new LogicException(
                "Content blocks table [{$tableName}] already exists; ".
                'disable content.migrations.enabled during controlled schema adoption.',
            );
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('definition_id')
                ->constrained(ContentConfiguration::table('definitions'))
                ->restrictOnDelete();
            $table->string('key', 191);
            $table->string('scope', 100)->default('global');
            $table->string('scope_key', 191)->default('*');
            $table->string('status', 30)->default('draft');
            $table->string('visibility', 30)->default('public');
            $table->json('values')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('definition_version');
            $table->string('definition_hash', 64);
            $table->json('definition_schema');
            $table->string('definition_view', 255)->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('published_by_type', 191)->nullable();
            $table->string('published_by_id', 191)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('created_by_type', 191)->nullable();
            $table->string('created_by_id', 191)->nullable();
            $table->string('updated_by_type', 191)->nullable();
            $table->string('updated_by_id', 191)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['scope', 'scope_key', 'key'], 'content_blocks_scope_key_unique');
            $table->index(['definition_id', 'status', 'visibility'], 'content_blocks_definition_state_idx');
            $table->index(
                ['definition_id', 'definition_version', 'id'],
                'content_blocks_definition_version_idx',
            );
            $table->index(['scope', 'scope_key', 'status'], 'content_blocks_scope_state_idx');
            $table->index(['status', 'published_at'], 'content_blocks_publication_idx');
            $table->index(
                ['published_by_type', 'published_by_id'],
                'content_blocks_published_by_idx',
            );
            $table->index(['created_by_type', 'created_by_id'], 'content_blocks_created_by_idx');
            $table->index(['updated_by_type', 'updated_by_id'], 'content_blocks_updated_by_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(ContentConfiguration::connection())
            ->dropIfExists(ContentConfiguration::table('blocks'));
    }
};
