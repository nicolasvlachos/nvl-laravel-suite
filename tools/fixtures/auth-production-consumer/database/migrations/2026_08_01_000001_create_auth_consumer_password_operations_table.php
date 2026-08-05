<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the host-owned password-update idempotency ledger.
     */
    public function up(): void
    {
        Schema::create('auth_consumer_password_operations', function (Blueprint $table): void {
            $table->uuid('operation_id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('applied_at');
            $table->timestampsTz();
        });
    }

    /**
     * Drop the disposable consumer fixture ledger.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_consumer_password_operations');
    }
};
