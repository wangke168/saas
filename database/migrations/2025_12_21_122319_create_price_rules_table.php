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
        if (Schema::hasTable('price_rules')) {
            return;
        }
        
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete()->comment('产品ID');
            $table->string('name')->comment('规则名称');
            $table->string('type')->comment('规则类型: weekday=周几规则, date_range=日期区间规则');
            $table->string('weekdays')->nullable()->comment('周几（1-7，逗号分隔，如：1,2,3）');
            $table->date('start_date')->nullable()->comment('开始日期');
            $table->date('end_date')->nullable()->comment('结束日期');
            $table->decimal('market_price_adjustment', 10, 2)->default(0)->comment('门市价调整（分，正数加价，负数减价）');
            $table->decimal('settlement_price_adjustment', 10, 2)->default(0)->comment('结算价调整（分）');
            $table->decimal('sale_price_adjustment', 10, 2)->default(0)->comment('销售价调整（分）');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
            
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_rules');
    }
};
