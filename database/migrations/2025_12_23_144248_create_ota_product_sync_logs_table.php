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
        if (Schema::hasTable('ota_product_sync_logs')) {
            return;
        }

        Schema::create('ota_product_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete()->comment('产品ID');
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete()->comment('酒店ID');
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete()->comment('房型ID');
            $table->foreignId('ota_platform_id')->constrained('ota_platforms')->cascadeOnDelete()->comment('OTA平台ID');
            $table->string('last_price_hash', 32)->nullable()->comment('上次价格哈希值(MD5)');
            $table->string('last_stock_hash', 32)->nullable()->comment('上次库存哈希值(MD5)');
            $table->timestamp('last_price_synced_at')->nullable()->comment('上次价格同步时间');
            $table->timestamp('last_stock_synced_at')->nullable()->comment('上次库存同步时间');
            $table->json('last_price_data')->nullable()->comment('上次价格数据(用于调试)');
            $table->json('last_stock_data')->nullable()->comment('上次库存数据(用于调试)');
            $table->timestamps();

            $table->unique(['product_id', 'hotel_id', 'room_type_id', 'ota_platform_id'], 'unique_combo');
            $table->index('product_id', 'idx_product_id');
            $table->index(['last_price_synced_at', 'last_stock_synced_at'], 'idx_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ota_product_sync_logs');
    }
};
