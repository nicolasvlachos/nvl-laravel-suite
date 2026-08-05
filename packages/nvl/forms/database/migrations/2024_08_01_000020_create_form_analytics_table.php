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
        if (Schema::hasTable(FormsTables::FORM_ANALYTICS)) {
            return;
        }

        Schema::create(FormsTables::FORM_ANALYTICS, function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('form_id')
                ->comment('Reference to the parent form')
                ->constrained(FormsTables::FORMS)
                ->onDelete('cascade');

            $table->string('event_type')
                ->comment('Type of event (view, submission, spam_blocked, etc.)');

            $table->string('origin')->nullable()
                ->comment('Origin domain where event occurred');

            $table->ipAddress('ip_address')->nullable()
                ->comment('IP address of the visitor');

            $table->text('user_agent')->nullable()
                ->comment('User agent string');

            $table->string('session_id')->nullable()
                ->comment('Session identifier');

            $table->json('metadata')->nullable()
                ->comment('Additional event metadata');

            $table->timestampsTz();

            // Indexes for performance and analytics
            $table->index('ip_address');
            $table->index(['form_id', 'event_type']);
            $table->index(['form_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index(['origin', 'created_at']);
            $table->index('created_at');
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
        Schema::dropIfExists(FormsTables::FORM_ANALYTICS);
        Schema::enableForeignKeyConstraints();
    }
};
