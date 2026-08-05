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
        if (Schema::hasTable(FormsTables::FORM_SUBMISSION_RECEIPTS)) {
            return;
        }

        Schema::create(FormsTables::FORM_SUBMISSION_RECEIPTS, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_id')
                ->constrained(FormsTables::FORMS)
                ->cascadeOnDelete();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('payload_digest', 64);
            $table->string('registration_fingerprint', 64)->nullable();
            $table->string('state', 16);
            $table->string('result_id')->nullable();
            $table->timestamps();

            $table->unique(['form_id', 'idempotency_key']);
            $table->unique(['form_id', 'registration_fingerprint']);
            $table->index(['state', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(FormsTables::FORM_SUBMISSION_RECEIPTS);
    }
};
