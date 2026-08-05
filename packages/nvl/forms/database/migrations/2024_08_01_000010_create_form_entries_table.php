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
        if (Schema::hasTable(FormsTables::FORM_ENTRIES)) {
            return;
        }

        Schema::create(FormsTables::FORM_ENTRIES, function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('form_id')
                ->comment('Reference to the parent form')
                ->constrained(FormsTables::FORMS)
                ->onDelete('cascade');

            $table->string('subject')->nullable()
                ->comment('Subject line for the form submission');

            $table->string('email')->nullable()
                ->comment('Email address provided in the submission');

            $table->string('first_name')->nullable()
                ->comment('First name provided in the submission');

            $table->string('last_name')->nullable()
                ->comment('Last name provided in the submission');

            $table->string('phone')->nullable()
                ->comment('Phone number provided in the submission');

            $table->text('address')->nullable()
                ->comment('Address information provided in the submission');

            $table->text('body')->nullable()
                ->comment('Main message/body content of the submission');

            $table->json('submission_data')->nullable()
                ->comment('Dynamic form field data stored as JSON');

            $table->string('submitted_from')
                ->comment('The host/domain from which the form was submitted');

            $table->ipAddress('ip_address')->nullable()
                ->comment('IP address of the submitter');

            $table->text('user_agent')->nullable()
                ->comment('User agent string for security tracking');

            $table->string('session_id')->nullable()
                ->comment('Session ID for tracking unique submissions');

            $table->boolean('is_spam')->default(false)
                ->comment('Whether submission was marked as spam');

            $table->unsignedTinyInteger('spam_score')->nullable()
                ->comment('Anti-spam score if available');

            $table->json('security_flags')->nullable()
                ->comment('Security-related flags and metadata');

            $table->string('idempotency_key', 128)->nullable();
            $table->string('payload_digest', 64)->nullable();
            $table->string('registration_fingerprint', 64)->nullable();
            $table->timestampTz('redacted_at')->nullable();
            $table->timestampTz('anonymized_at')->nullable();

            $table->timestampsTz();

            // Indexes for performance
            $table->index('submitted_from');
            $table->index('session_id');
            $table->index(['form_id', 'created_at']);
            $table->index(['email', 'created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index(['is_spam', 'created_at']);
            $table->index(['form_id', 'payload_digest']);
            $table->unique(['form_id', 'idempotency_key']);
            $table->unique(['form_id', 'registration_fingerprint']);
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
        Schema::dropIfExists(FormsTables::FORM_ENTRIES);
        Schema::enableForeignKeyConstraints();
    }
};
