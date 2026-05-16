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
        if (Schema::hasTable('exception_orders')) {
            return;
        }
        
        Schema::create('exception_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete()->comment('订单ID');
            $table->string('exception_type')->comment('异常类型: api_error=接口报错, timeout=超时, inventory_mismatch=库存不匹配, price_mismatch=价格不匹配');
            $table->text('exception_message')->comment('异常信息');
            $table->text('exception_data')->nullable()->comment('异常数据（JSON）');
            $table->string('status')->default('pending')->comment('处理状态: pending=待处理, processing=处理中, resolved=已解决');
            $table->foreignId('handler_id')->nullable()->constrained('users')->nullOnDelete()->comment('处理人ID');
            $table->timestamp('resolved_at')->nullable()->comment('解决时间');
            $table->timestamps();
            
            $table->index('order_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exception_orders');
    }
};
