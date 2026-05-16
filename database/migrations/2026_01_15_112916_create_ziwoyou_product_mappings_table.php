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
        Schema::create('ziwoyou_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete()
                ->comment('本地产品ID');
            $table->foreignId('hotel_id')
                ->constrained('hotels')
                ->cascadeOnDelete()
                ->comment('本地酒店ID');
            $table->foreignId('room_type_id')
                ->constrained('room_types')
                ->cascadeOnDelete()
                ->comment('本地房型ID');
            $table->string('ziwoyou_product_id', 50)
                ->comment('自我游产品ID');
            $table->foreignId('scenic_spot_id')
                ->constrained('scenic_spots')
                ->cascadeOnDelete()
                ->comment('景区ID（冗余，便于查询）');
            $table->boolean('is_active')
                ->default(true)
                ->comment('是否启用');
            $table->timestamps();
            
            // 唯一索引：确保每个SKU组合只有一个映射
            $table->unique(['product_id', 'hotel_id', 'room_type_id'], 'sku_unique');
            
            // 普通索引
            $table->index('ziwoyou_product_id');
            $table->index('scenic_spot_id');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ziwoyou_product_mappings');
    }
};
