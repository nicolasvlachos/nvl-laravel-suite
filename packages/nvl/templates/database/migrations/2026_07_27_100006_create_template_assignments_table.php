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
     * Create polymorphic template assignments.
     */
    public function up(): void
    {
        $schema = Schema::connection(TemplatesConfiguration::connection());
        $tableName = TemplatesConfiguration::table(TemplatesTables::Assignments);

        if ($schema->hasTable($tableName)) {
            throw new LogicException(
                "Template assignments table [{$tableName}] already exists; disable templates.migrations.enabled during controlled schema adoption.",
            );
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('template_id');
            $table->uuid('template_version_id')->nullable();
            $table->string('owner_type', 100);
            $table->string('owner_id');
            $table->string('profile', 100)->default('default');
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();

            $table->foreign('template_id')
                ->references('id')
                ->on(TemplatesConfiguration::table(TemplatesTables::Templates))
                ->cascadeOnDelete();
            $table->foreign('template_version_id')
                ->references('id')
                ->on(TemplatesConfiguration::table(TemplatesTables::Versions))
                ->nullOnDelete();
            $table->unique(
                ['owner_type', 'owner_id', 'profile'],
                'template_assignments_owner_profile_unique',
            );
            $table->index(
                ['template_id', 'template_version_id'],
                'template_assignments_template_version_idx',
            );
        });
    }

    /**
     * Drop template assignments.
     */
    public function down(): void
    {
        Schema::connection(TemplatesConfiguration::connection())
            ->dropIfExists(TemplatesConfiguration::table(TemplatesTables::Assignments));
    }
};
