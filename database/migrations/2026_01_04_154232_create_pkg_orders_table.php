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
        if (Schema::hasTable('pkg_orders')) {
            return;
        }
        
        Schema::create('pkg_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 50)->unique()->comment('订单号');
            $table->string('ota_order_no', 100)->nullable()->comment('OTA平台订单号');
            $table->foreignId('ota_platform_id')->nullable()->constrained('ota_platforms')->onDelete('set null')->comment('OTA平台ID');
            $table->foreignId('pkg_product_id')->constrained('pkg_products')->onDelete('restrict')->comment('打包产品ID');
            $table->foreignId('hotel_id')->constrained('res_hotels')->onDelete('restrict')->comment('酒店ID');
            $table->foreignId('room_type_id')->constrained('res_room_types')->onDelete('restrict')->comment('房型ID');
            $table->date('check_in_date')->comment('入住日期');
            $table->date('check_out_date')->comment('离店日期');
            $table->integer('stay_days')->default(1)->comment('入住天数');
            $table->decimal('total_amount', 10, 2)->comment('订单总金额');
            $table->decimal('settlement_amount', 10, 2)->nullable()->comment('结算金额');
            $table->string('contact_name', 100)->comment('联系人姓名');
            $table->string('contact_phone', 20)->comment('联系电话');
            $table->string('contact_email', 100)->nullable()->comment('联系邮箱');
            $table->string('status', 20)->default('PAID')->comment('订单状态：PAID=已支付, CONFIRMED=已确认, FAILED=失败, CANCELLED=已取消');
            $table->timestamp('paid_at')->nullable()->comment('支付时间');
            $table->timestamp('confirmed_at')->nullable()->comment('确认时间');
            $table->timestamp('cancelled_at')->nullable()->comment('取消时间');
            $table->timestamps();
            
            $table->index('ota_order_no', 'idx_ota_order_no');
            $table->index('pkg_product_id', 'idx_product');
            $table->index('status', 'idx_status');
            $table->index('check_in_date', 'idx_check_in_date');
            $table->index('created_at', 'idx_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pkg_orders');
    }
};
