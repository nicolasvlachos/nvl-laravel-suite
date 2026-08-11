<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Templates\Definitions\Tables\TemplatesTables;
use Nvl\Templates\Support\TemplatesConfiguration;

return new class extends Migration
{
    /**
     * Create structural template definitions.
     */
    public function up(): void
    {
        $schema = Schema::connection(TemplatesConfiguration::connection());
        $tableName = TemplatesConfiguration::table(TemplatesTables::Templates);

        if ($schema->hasTable($tableName)) {
            throw new LogicException(
                "Templates table [{$tableName}] already exists; disable templates.migrations.enabled during controlled schema adoption.",
            );
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 191)->unique();
            $table->string('renderer', 100)->default('blade')->index();
            $table->string('status', 32)->default('active')->index();
            $table->json('schema')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['status', 'updated_at'], 'templates_status_updated_idx');
        });
    }

    /**
     * Drop structural template definitions.
     */
    public function down(): void
    {
        Schema::connection(TemplatesConfiguration::connection())
            ->dropIfExists(TemplatesConfiguration::table(TemplatesTables::Templates));
    }
};
