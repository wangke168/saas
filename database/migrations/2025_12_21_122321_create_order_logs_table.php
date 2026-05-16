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
        if (Schema::hasTable('order_logs')) {
            return;
        }
        
        Schema::create('order_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete()->comment('订单ID');
            $table->string('from_status')->nullable()->comment('原状态');
            $table->string('to_status')->comment('新状态');
            $table->text('remark')->nullable()->comment('备注');
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete()->comment('操作人ID');
            $table->timestamps();
            
            $table->index('order_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_logs');
    }
};
