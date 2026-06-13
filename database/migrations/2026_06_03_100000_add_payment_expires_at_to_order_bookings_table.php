<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_bookings', function (Blueprint $table) {
            $table->timestamp('payment_expires_at')
                ->nullable()
                ->after('paid_at')
                ->comment('待支付预约超时时间（超时自动取消）');
            $table->index(['status', 'payment_expires_at'], 'order_bookings_pending_payment_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_bookings', function (Blueprint $table) {
            $table->dropIndex('order_bookings_pending_payment_expires_idx');
            $table->dropColumn('payment_expires_at');
        });
    }
};
