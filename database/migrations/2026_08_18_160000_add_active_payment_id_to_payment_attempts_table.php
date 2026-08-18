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
        if (! Schema::hasColumn('payment_attempts', 'active_payment_id')) {
            Schema::table('payment_attempts', function (Blueprint $table) {
                $table->uuid('active_payment_id')->nullable();
                $table->unique('active_payment_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('payment_attempts', 'active_payment_id')) {
            Schema::table('payment_attempts', function (Blueprint $table) {
                $table->dropUnique(['active_payment_id']);
                $table->dropColumn('active_payment_id');
            });
        }
    }
};
