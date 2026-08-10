<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Templates\Support\TemplatesConfiguration;

return new class extends Migration
{
    /**
     * Create versioned structural publication records.
     */
    public function up(): void
    {
        $schema = Schema::connection(TemplatesConfiguration::connection());
        $tableName = TemplatesConfiguration::table('template_versions');

        if ($schema->hasTable($tableName)) {
            throw new LogicException(
                "Template versions table [{$tableName}] already exists; disable templates.migrations.enabled during controlled schema adoption.",
            );
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('template_id');
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->json('metadata')->nullable();
            $table->json('content_snapshot')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('published_by_type')->nullable();
            $table->string('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('template_id')
                ->references('id')
                ->on(TemplatesConfiguration::table('templates'))
                ->cascadeOnDelete();
            $table->unique(['template_id', 'version'], 'template_versions_number_unique');
            $table->index(['template_id', 'status', 'version'], 'template_versions_resolution_idx');
            $table->index(['published_by_type', 'published_by'], 'template_versions_publisher_idx');
        });
    }

    /**
     * Drop template versions.
     */
    public function down(): void
    {
        Schema::connection(TemplatesConfiguration::connection())
            ->dropIfExists(TemplatesConfiguration::table('template_versions'));
    }
};
