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
     * Create localized template metadata.
     */
    public function up(): void
    {
        $schema = Schema::connection(TemplatesConfiguration::connection());
        $tableName = TemplatesConfiguration::table(TemplatesTables::I18n);

        if ($schema->hasTable($tableName)) {
            throw new LogicException(
                "Templates translations table [{$tableName}] already exists; disable templates.migrations.enabled during controlled schema adoption.",
            );
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('template_id');
            $table->string('locale', 35);
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('template_id')
                ->references('id')
                ->on(TemplatesConfiguration::table(TemplatesTables::Templates))
                ->cascadeOnDelete();
            $table->unique(['template_id', 'locale'], 'templates_i18n_owner_locale_unique');
            $table->index(['locale', 'title'], 'templates_i18n_locale_title_idx');
        });
    }

    /**
     * Drop localized template metadata.
     */
    public function down(): void
    {
        Schema::connection(TemplatesConfiguration::connection())
            ->dropIfExists(TemplatesConfiguration::table(TemplatesTables::I18n));
    }
};
