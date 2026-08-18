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
        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('notification_type');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['payment_id', 'notification_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
    }
};
