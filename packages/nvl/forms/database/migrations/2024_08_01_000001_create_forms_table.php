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
        if (Schema::hasTable(FormsTables::Forms)) {
            return;
        }

        Schema::create(FormsTables::Forms, function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('handle')->unique()
                ->comment('Unique identifier for the form (URL-friendly)');

            // Behavior enums (use string columns; cast in model)
            $table->string('resolvement', 16)->default('entries')
                ->comment('How the form resolves submissions: entries|custom');
            $table->string('type', 16)->default('landing_page')
                ->comment('Form delivery context: landing_page|iframe');

            $table->string('status', 16)->default('draft')
                ->comment('Lifecycle status of the form (draft, active, paused, archived)');

            $table->boolean('restrict_public_access')->default(false)
                ->comment('Whether to enforce host restrictions for form access');

            // Availability controls
            $table->boolean('allow_multiple_registrations')->default(true)
                ->comment('Allow multiple registrations per client (controls persistent thank-you behavior)');

            $table->boolean('date_restricted')->default(false)
                ->comment('When true, form availability is restricted to a date window');

            $table->timestampTz('available_from')->nullable()
                ->comment('Form becomes available at or after this datetime');

            $table->timestampTz('available_until')->nullable()
                ->comment('Form is available until this datetime (inclusive)');

            $table->unsignedInteger('submissions_count')->default(0)
                ->comment('Total number of submissions received for this form');

            $table->unsignedInteger('views_count')->default(0)
                ->comment('Total number of times form was viewed');

            $table->unsignedInteger('spam_count')->default(0)
                ->comment('Total number of spam submissions blocked');

            $table->timestampTz('last_used_at')->nullable()
                ->comment('Timestamp of the last form submission');

            $table->timestampTz('first_used_at')->nullable()
                ->comment('Timestamp of the first recorded submission');

            $table->boolean('enable_honeypot')->default(true)
                ->comment('Enable honeypot spam protection');

            $table->boolean('enable_rate_limiting')->default(true)
                ->comment('Enable rate limiting per IP');

            $table->unsignedInteger('rate_limit_per_hour')->default(10)
                ->comment('Maximum submissions per hour per IP');

            $table->boolean('require_csrf')->default(true)
                ->comment('Whether CSRF token is required');

            $table->json('cors_settings')->nullable()
                ->comment('CORS configuration for iframe embedding');

            $table->json('options')->nullable()
                ->comment('Headless rendering and behavior options');

            $table->unsignedBigInteger('revision')->default(1)
                ->comment('Optimistic concurrency revision');

            $table->softDeletesTz();
            $table->timestampsTz();

            // Indexes for performance
            $table->index('status');
            $table->index('resolvement');
            $table->index('type');
            $table->index('available_from');
            $table->index('available_until');
            $table->index(['submissions_count', 'created_at']);
            $table->index(['views_count', 'created_at']);
            $table->index(['spam_count', 'created_at']);
            $table->index('last_used_at');
            $table->index('first_used_at');
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
        Schema::dropIfExists(FormsTables::Forms);
        Schema::enableForeignKeyConstraints();
    }
};
