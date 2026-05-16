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
        Schema::create('system_pkg_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('system_pkg_orders')->onDelete('cascade')->comment('主订单ID');
            
            // 资源信息
            $table->string('item_type', 20)->comment('TICKET=门票, HOTEL=酒店');
            $table->unsignedBigInteger('resource_id')->comment('资源ID');
            $table->string('resource_name', 255)->nullable()->comment('资源名称（冗余字段，便于查询）');
            $table->integer('quantity')->default(1)->comment('数量');
            
            // 价格信息
            $table->decimal('unit_price', 10, 2)->nullable()->comment('单价');
            $table->decimal('total_price', 10, 2)->nullable()->comment('总价');
            
            // 处理状态
            $table->string('status', 20)->default('PENDING')->comment('状态：PENDING=待处理, PROCESSING=处理中, SUCCESS=成功, FAILED=失败');
            $table->string('resource_order_no', 100)->nullable()->comment('资源方订单号（门票/酒店订单号）');
            $table->text('error_message')->nullable()->comment('错误信息');
            
            // 重试信息（第三方库存）
            $table->integer('retry_count')->default(0)->comment('重试次数');
            $table->integer('max_retries')->default(3)->comment('最大重试次数');
            
            // 处理时间
            $table->timestamp('processed_at')->nullable()->comment('处理时间');
            
            $table->timestamps();
            
            $table->index('order_id');
            $table->index('item_type');
            $table->index('status');
            $table->index('resource_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_pkg_order_items');
    }
};




