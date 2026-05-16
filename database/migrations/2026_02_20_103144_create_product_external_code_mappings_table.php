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
        Schema::create('product_external_code_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete()->comment('产品ID');
            $table->string('external_code', 255)->comment('横店系统产品编码');
            $table->date('start_date')->comment('生效开始日期');
            $table->date('end_date')->comment('生效结束日期');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->integer('sort_order')->default(0)->comment('排序（用于处理重叠时间段）');
            $table->timestamps();
            $table->softDeletes();
            
            // 索引
            $table->index(['product_id', 'start_date', 'end_date'], 'idx_product_date');
            $table->index(['product_id', 'is_active'], 'idx_product_active');
            
            // 唯一约束：同一产品在同一时间段不能有重复的映射（排除已删除的记录）
            // 注意：Laravel 的 unique 约束不支持软删除，需要在应用层验证
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_external_code_mappings');
    }
};
