<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete()->comment('OTA 父订单');
            $table->foreignId('product_id')->constrained('products')->comment('预售产品');
            $table->unsignedSmallInteger('line_no')->default(1)->comment('份数序号，从 1 开始');
            $table->string('entitlement_no', 32)->unique()->comment('权益编号');
            $table->string('status', 20)->default('pending')->comment('pending/booking/booked/cancelled');
            $table->decimal('base_price', 10, 2)->default(0)->comment('预售已付基础价（元）');
            $table->foreignId('order_booking_id')->nullable()->comment('关联预约单');
            $table->timestamp('booked_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'line_no']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_entitlements');
    }
};
