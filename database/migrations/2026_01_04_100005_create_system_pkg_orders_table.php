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
        Schema::create('system_pkg_orders', function (Blueprint $table) {
            $table->id();
            
            // 订单标识
            $table->string('order_no', 50)->unique()->comment('订单号');
            $table->string('ota_order_no', 100)->nullable()->comment('OTA平台订单号');
            $table->foreignId('ota_platform_id')->nullable()->constrained('ota_platforms')->onDelete('set null')->comment('OTA平台ID');
            
            // 产品信息
            $table->foreignId('sales_product_id')->constrained('sales_products')->onDelete('restrict')->comment('销售产品ID');
            $table->string('ota_product_code', 50)->nullable()->comment('OTA产品编码（冗余字段，便于查询）');
            
            // 订单信息
            $table->date('check_in_date')->comment('入住日期');
            $table->date('check_out_date')->comment('离店日期');
            $table->integer('stay_days')->default(1)->comment('入住天数');
            
            // 价格信息
            $table->decimal('total_amount', 10, 2)->comment('订单总金额（分）');
            $table->decimal('settlement_amount', 10, 2)->nullable()->comment('结算金额（分）');
            
            // 联系人信息
            $table->string('contact_name', 100)->comment('联系人姓名');
            $table->string('contact_phone', 20)->comment('联系人电话');
            $table->string('contact_email', 100)->nullable()->comment('联系人邮箱');
            
            // 入住人信息
            $table->integer('guest_count')->default(2)->comment('入住人数');
            $table->json('guest_info')->nullable()->comment('入住人信息（JSON格式）');
            
            // 订单状态
            $table->string('status', 20)->default('PAID_PENDING')->comment('订单状态');
            
            // 资源方订单号
            $table->string('resource_order_no', 100)->nullable()->comment('资源方订单号/确认号');
            
            // 时间戳
            $table->timestamp('paid_at')->nullable()->comment('支付时间');
            $table->timestamp('confirmed_at')->nullable()->comment('确认时间');
            $table->timestamp('cancelled_at')->nullable()->comment('取消时间');
            
            $table->timestamps();
            
            $table->index('ota_order_no');
            $table->index('sales_product_id');
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
        Schema::dropIfExists('system_pkg_orders');
    }
};




