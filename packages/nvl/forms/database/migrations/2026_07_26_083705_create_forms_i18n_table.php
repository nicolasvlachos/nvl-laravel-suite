<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Forms\Definitions\Tables\FormsTables;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(FormsTables::I18n)) {
            return;
        }

        Schema::create(FormsTables::I18n, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_id')
                ->constrained(FormsTables::Forms)
                ->cascadeOnDelete();
            $table->string('locale', 35);
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('submit_button_label', 100)->nullable();
            $table->string('success_title')->nullable();
            $table->text('success_message')->nullable();
            $table->json('content')->nullable();
            $table->timestampsTz();

            $table->unique(['form_id', 'locale']);
            $table->index(['locale', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(FormsTables::I18n);
    }
};
