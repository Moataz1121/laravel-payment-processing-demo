<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_gateway_id')->constrained('payment_gateways')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('payment_reference')->unique();
            $table->string('idempotency_key');
            $table->string('transaction_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'cancelled',
                'refunded',
                'partially_refunded',
                'expired',
                'disputed',
            ])->default('pending');
            $table->enum('payment_method', [
                'card',
                'wallet',
                'bank_transfer',
                'cash_on_delivery',
            ])->nullable();
            $table->timestamp('payment_initiated_at')->nullable();
            $table->timestamp('payment_completed_at')->nullable();
            $table->timestamp('payment_failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('payment_url')->nullable();
            $table->text('success_url')->nullable();
            $table->text('failure_url')->nullable();
            $table->text('cancel_url')->nullable();
            $table->string('failure_reason')->nullable();
            $table->text('failure_message')->nullable();
            $table->string('gateway_error_code')->nullable();
            $table->decimal('refunded_amount', 12, 2)->default(0.00);
            $table->integer('refund_attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
