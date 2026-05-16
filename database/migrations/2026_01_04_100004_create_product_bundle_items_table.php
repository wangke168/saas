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
        Schema::create('product_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_product_id')->constrained('sales_products')->onDelete('cascade')->comment('关联sales_products.id');
            
            // 资源信息
            $table->string('resource_type', 20)->comment('TICKET=门票, HOTEL=酒店');
            $table->unsignedBigInteger('resource_id')->comment('资源ID：门票关联tickets.id，酒店关联res_room_types.id');
            $table->integer('quantity')->default(1)->comment('数量');
            
            // 排序
            $table->integer('sort_order')->default(0)->comment('排序（用于前端展示）');
            
            $table->timestamps();
            
            $table->index('sales_product_id');
            $table->index(['resource_type', 'resource_id']);
            $table->unique(['sales_product_id', 'resource_type', 'resource_id'], 'uk_product_resource');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_bundle_items');
    }
};




