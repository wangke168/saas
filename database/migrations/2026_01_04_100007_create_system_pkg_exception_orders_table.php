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
        Schema::create('system_pkg_exception_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('system_pkg_orders')->onDelete('cascade')->comment('订单ID');
            
            // 异常信息
            $table->string('exception_type', 50)->comment('异常类型');
            $table->text('exception_message')->nullable()->comment('异常信息');
            $table->json('exception_data')->nullable()->comment('异常数据（JSON格式）');
            
            // 处理状态
            $table->string('status', 20)->default('PENDING')->comment('状态：PENDING=待处理, PROCESSING=处理中, RESOLVED=已解决');
            $table->foreignId('handler_id')->nullable()->constrained('users')->onDelete('set null')->comment('处理人ID');
            $table->timestamp('resolved_at')->nullable()->comment('解决时间');
            
            // 备注
            $table->text('remark')->nullable()->comment('处理备注');
            
            $table->timestamps();
            
            $table->index('order_id');
            $table->index('status');
            $table->index('exception_type');
            $table->index('handler_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_pkg_exception_orders');
    }
};




