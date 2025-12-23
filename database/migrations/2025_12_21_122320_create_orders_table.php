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
        if (Schema::hasTable('orders')) {
            return;
        }
        
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique()->comment('订单号');
            $table->string('ota_order_no')->nullable()->comment('OTA平台订单号');
            $table->unsignedBigInteger('ota_platform_id')->nullable()->comment('OTA平台ID');
            $table->foreignId('product_id')->constrained('products')->comment('产品ID');
            $table->foreignId('hotel_id')->constrained('hotels')->comment('酒店ID');
            $table->foreignId('room_type_id')->constrained('room_types')->comment('房型ID');
            $table->string('status')->comment('订单状态: paid_pending=已支付/待确认, confirming=确认中, confirmed=预订成功, rejected=预订失败/拒单, cancel_requested=申请取消中, cancel_rejected=取消拒绝, cancel_approved=取消通过, verified=核销订单');
            $table->date('check_in_date')->comment('入住日期');
            $table->date('check_out_date')->comment('离店日期');
            $table->integer('room_count')->default(1)->comment('房间数');
            $table->integer('guest_count')->default(2)->comment('入住人数');
            $table->string('contact_name')->comment('联系人姓名');
            $table->string('contact_phone')->comment('联系人电话');
            $table->string('contact_email')->nullable()->comment('联系人邮箱');
            $table->text('guest_info')->nullable()->comment('入住人信息（JSON）');
            $table->decimal('total_amount', 10, 2)->default(0)->comment('订单总金额（分）');
            $table->decimal('settlement_amount', 10, 2)->default(0)->comment('结算金额（分）');
            $table->string('resource_order_no')->nullable()->comment('资源方订单号/确认号');
            $table->unsignedBigInteger('resource_provider_id')->nullable()->comment('资源方ID');
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamp('paid_at')->nullable()->comment('支付时间');
            $table->timestamp('confirmed_at')->nullable()->comment('确认时间');
            $table->timestamp('cancelled_at')->nullable()->comment('取消时间');
            $table->timestamps();
            
            $table->index('ota_order_no');
            $table->index('status');
            $table->index('check_in_date');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
