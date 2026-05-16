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
        if (Schema::hasTable('pkg_product_bundle_items')) {
            return;
        }
        
        Schema::create('pkg_product_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pkg_product_id')->constrained('pkg_products')->onDelete('cascade')->comment('打包产品ID');
            $table->unsignedBigInteger('ticket_id')->comment('门票ID（关联tickets.id）');
            $table->integer('quantity')->default(1)->comment('数量');
            $table->timestamps();
            
            $table->unique(['pkg_product_id', 'ticket_id'], 'uk_product_ticket');
            $table->index('pkg_product_id', 'idx_product');
            $table->index('ticket_id', 'idx_ticket');
            
            // 注意：tickets表如果不存在，需要先创建tickets表后再添加外键约束
            // 可以在后续迁移中添加：$table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pkg_product_bundle_items');
    }
};
