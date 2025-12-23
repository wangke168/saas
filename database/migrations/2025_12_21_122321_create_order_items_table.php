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
        if (Schema::hasTable('order_items')) {
            return;
        }
        
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete()->comment('订单ID');
            $table->date('date')->comment('日期');
            $table->integer('quantity')->default(1)->comment('数量');
            $table->decimal('unit_price', 10, 2)->default(0)->comment('单价（分）');
            $table->decimal('total_price', 10, 2)->default(0)->comment('总价（分）');
            $table->timestamps();
            
            $table->index(['order_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
