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
        $tableName = ContentConfiguration::table('blocks_i18n');

        if ($schema->hasTable($tableName)) {
            throw new LogicException(
                "Content translations table [{$tableName}] already exists; ".
                'disable content.migrations.enabled during controlled schema adoption.',
            );
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_block_id')
                ->constrained(ContentConfiguration::table('blocks'))
                ->cascadeOnDelete();
            $table->string('locale', 35);
            $table->json('values');
            $table->timestamps();

            $table->unique(['content_block_id', 'locale'], 'content_blocks_i18n_locale_unique');
            $table->index(['locale', 'content_block_id'], 'content_blocks_i18n_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(ContentConfiguration::connection())
            ->dropIfExists(ContentConfiguration::table('blocks_i18n'));
    }
};
