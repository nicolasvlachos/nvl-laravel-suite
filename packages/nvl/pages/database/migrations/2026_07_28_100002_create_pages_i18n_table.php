<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Pages\Definitions\Tables\PagesTables;

return new class extends Migration
{
    /**
     * Create the related page translation table.
     */
    public function up(): void
    {
        $connection = config('pages.connection');
        $schema = Schema::connection(is_string($connection) ? $connection : null);
        $tableName = (string) config('pages.tables.pages_i18n', PagesTables::I18n);

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('page_id');
            $table->string('locale', 35);
            $table->string('title');
            $table->string('navigation_label')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'locale'], 'pages_i18n_owner_locale_unique');
            $table->index(['locale', 'title'], 'pages_i18n_locale_title_index');
            $table->foreign('page_id')
                ->references('id')
                ->on((string) config('pages.tables.pages', PagesTables::Pages))
                ->cascadeOnDelete();
        });
    }

    /**
     * Drop the page translation table.
     */
    public function down(): void
    {
        $connection = config('pages.connection');
        Schema::connection(is_string($connection) ? $connection : null)
            ->dropIfExists((string) config('pages.tables.pages_i18n', PagesTables::I18n));
    }
};
