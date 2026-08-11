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
        if (Schema::hasTable(FormsTables::RateLimits)) {
            return;
        }

        Schema::create(FormsTables::RateLimits, function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('form_id')
                ->comment('Reference to the parent form')
                ->constrained(FormsTables::Forms)
                ->onDelete('cascade');

            $table->ipAddress('ip_address')
                ->comment('IP address being rate limited');

            $table->unsignedInteger('submission_count')->default(0)
                ->comment('Number of submissions in current time window');

            $table->timestampTz('window_start')
                ->comment('Start of the current rate limit window');

            $table->timestampTz('last_submission_at')
                ->comment('Timestamp of last submission attempt');

            $table->boolean('is_blocked')->default(false)
                ->comment('Whether IP is currently blocked');

            $table->timestampTz('blocked_until')->nullable()
                ->comment('Until when IP is blocked (null if not blocked)');

            $table->unsignedInteger('violation_count')->default(0)
                ->comment('Number of rate limit violations');

            $table->timestampsTz();

            // Indexes for performance
            $table->index('window_start');
            $table->index('blocked_until');
            $table->index('last_submission_at');

            // Unique constraint
            $table->unique(['form_id', 'ip_address']);
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
        Schema::dropIfExists(FormsTables::RateLimits);
        Schema::enableForeignKeyConstraints();
    }
};
