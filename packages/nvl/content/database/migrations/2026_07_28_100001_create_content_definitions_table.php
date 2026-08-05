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
        $tableName = ContentConfiguration::table('definitions');

        if ($schema->hasTable($tableName)) {
            throw new LogicException(
                "Content definitions table [{$tableName}] already exists; ".
                'disable content.migrations.enabled during controlled schema adoption.',
            );
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 191)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('category', 100)->default('content');
            $table->unsignedInteger('version')->default(1);
            $table->string('view', 255)->nullable();
            $table->json('schema');
            $table->json('defaults')->nullable();
            $table->json('allowed_scopes')->nullable();
            $table->json('allowed_regions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('source_hash', 64);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'category', 'sort_order'], 'content_definitions_browse_idx');
            $table->index(['source_hash', 'orphaned_at'], 'content_definitions_sync_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(ContentConfiguration::connection())
            ->dropIfExists(ContentConfiguration::table('definitions'));
    }
};
