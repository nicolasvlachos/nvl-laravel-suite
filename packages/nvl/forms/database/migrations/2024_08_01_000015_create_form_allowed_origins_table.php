<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Forms\Definitions\Tables\FormsTables;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @throws RuntimeException
     */
    public function up(): void
    {
        if (Schema::hasTable(FormsTables::ALLOWED_ORIGINS)) {
            return;
        }

        Schema::create(FormsTables::ALLOWED_ORIGINS, function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('form_id')
                ->comment('Reference to the parent form')
                ->constrained(FormsTables::FORMS)
                ->onDelete('cascade');

            $table->string('origin')
                ->comment('Allowed origin domain (supports wildcards)');

            $table->boolean('is_active')->default(true)
                ->comment('Whether this origin rule is currently active');

            $table->string('description')->nullable()
                ->comment('Optional description for this origin rule');

            $table->json('cors_settings')->nullable()
                ->comment('Specific CORS settings for this origin');

            $table->unsignedInteger('usage_count')->default(0)
                ->comment('Number of times this origin was used');

            $table->timestampTz('last_used_at')->nullable()
                ->comment('Last time form was accessed from this origin');

            $table->timestampsTz();

            // Indexes for performance
            $table->index(['form_id', 'is_active']);
            $table->index('last_used_at');

            // Unique constraint
            $table->unique(['form_id', 'origin']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @throws RuntimeException
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists(FormsTables::ALLOWED_ORIGINS);
        Schema::enableForeignKeyConstraints();
    }
};
