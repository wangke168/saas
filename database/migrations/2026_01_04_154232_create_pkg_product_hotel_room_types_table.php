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
        if (Schema::hasTable('pkg_product_hotel_room_types')) {
            return;
        }
        
        Schema::create('pkg_product_hotel_room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pkg_product_id')->constrained('pkg_products')->onDelete('cascade')->comment('打包产品ID');
            $table->foreignId('hotel_id')->constrained('res_hotels')->onDelete('restrict')->comment('酒店ID（关联res_hotels.id）');
            $table->foreignId('room_type_id')->constrained('res_room_types')->onDelete('restrict')->comment('房型ID（关联res_room_types.id）');
            $table->timestamps();
            
            $table->unique(['pkg_product_id', 'hotel_id', 'room_type_id'], 'uk_product_hotel_room');
            $table->index('pkg_product_id', 'idx_product');
            $table->index('hotel_id', 'idx_hotel');
            $table->index('room_type_id', 'idx_room_type');
            $table->index(['hotel_id', 'room_type_id'], 'idx_hotel_room');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pkg_product_hotel_room_types');
    }
};
