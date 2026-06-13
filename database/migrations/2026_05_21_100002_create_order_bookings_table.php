<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_no', 32)->unique()->comment('预约单号');
            $table->foreignId('order_entitlement_id')->constrained('order_entitlements')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->comment('OTA 父订单');
            $table->foreignId('presale_product_id')->constrained('products')->comment('预售产品');
            $table->foreignId('package_product_id')->nullable()->constrained('products')->nullOnDelete()->comment('履约 package 产品');
            $table->foreignId('hotel_id')->nullable()->constrained('hotels')->nullOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained('room_types')->nullOnDelete();
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->string('guest_name')->nullable();
            $table->string('guest_phone', 20)->nullable();
            $table->string('guest_id_card', 32)->nullable();
            $table->decimal('package_sale_price', 10, 2)->default(0)->comment('套餐日历售价');
            $table->decimal('base_price', 10, 2)->default(0)->comment('预售基础价');
            $table->decimal('surcharge_amount', 10, 2)->default(0)->comment('补差价');
            $table->string('status', 30)->default('pending_payment');
            $table->string('payment_no')->nullable()->comment('微信支付单号');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('fulfilled_order_id')->nullable()->constrained('orders')->nullOnDelete()->comment('占房后正式订单');
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('order_entitlement_id');
        });

        Schema::table('order_entitlements', function (Blueprint $table) {
            $table->foreign('order_booking_id')
                ->references('id')
                ->on('order_bookings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_entitlements', function (Blueprint $table) {
            $table->dropForeign(['order_booking_id']);
        });

        Schema::dropIfExists('order_bookings');
        Schema::dropIfExists('order_entitlements');
    }
};
