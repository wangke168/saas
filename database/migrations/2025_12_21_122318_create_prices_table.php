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
        if (Schema::hasTable('prices')) {
            return;
        }
        
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete()->comment('产品ID');
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete()->comment('房型ID');
            $table->date('date')->comment('日期');
            $table->decimal('market_price', 10, 2)->default(0)->comment('门市价（分）');
            $table->decimal('settlement_price', 10, 2)->default(0)->comment('结算价（分）');
            $table->decimal('sale_price', 10, 2)->default(0)->comment('销售价（分）');
            $table->string('source')->default('manual')->comment('价格来源: manual=人工维护, api=接口推送');
            $table->timestamps();
            
            $table->unique(['product_id', 'room_type_id', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
