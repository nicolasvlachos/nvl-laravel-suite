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
        $tableName = ContentConfiguration::table('revisions');

        if ($schema->hasTable($tableName)) {
            throw new LogicException(
                "Content revisions table [{$tableName}] already exists; ".
                'disable content.migrations.enabled during controlled schema adoption.',
            );
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_block_id')
                ->constrained(ContentConfiguration::table('blocks'))
                ->cascadeOnDelete();
            $table->unsignedBigInteger('revision');
            $table->string('event', 50);
            $table->json('snapshot');
            $table->string('actor_type', 191)->nullable();
            $table->string('actor_id', 191)->nullable();
            $table->timestamps();

            $table->unique(['content_block_id', 'revision'], 'content_revisions_block_unique');
            $table->index(['event', 'created_at'], 'content_revisions_event_idx');
            $table->index(['actor_type', 'actor_id'], 'content_revisions_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(ContentConfiguration::connection())
            ->dropIfExists(ContentConfiguration::table('revisions'));
    }
};
