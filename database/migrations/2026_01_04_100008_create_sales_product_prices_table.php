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
        Schema::create('sales_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_product_id')->constrained('sales_products')->onDelete('cascade')->comment('销售产品ID');
            $table->date('date')->comment('日期');
            
            // 价格信息
            $table->decimal('sale_price', 10, 2)->comment('销售价');
            $table->decimal('settlement_price', 10, 2)->nullable()->comment('结算价');
            
            // 价格明细（JSON格式，用于记录价格组成）
            $table->json('price_breakdown')->nullable()->comment('价格明细：{"hotel": 100, "tickets": [{"name": "门票A", "price": 50}]}');
            
            // 库存信息（可选，用于库存控制）
            $table->integer('stock_available')->nullable()->comment('可用库存（可选）');
            
            // 更新时间（用于判断是否需要更新）
            $table->timestamp('last_updated_at')->useCurrent()->useCurrentOnUpdate()->comment('最后更新时间');
            
            $table->timestamps();
            
            $table->unique(['sales_product_id', 'date'], 'uk_product_date');
            $table->index('date');
            $table->index('sales_product_id');
            $table->index('last_updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_product_prices');
    }
};




