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
        if (Schema::hasTable('pkg_product_daily_prices')) {
            return;
        }
        
        Schema::create('pkg_product_daily_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pkg_product_id')->constrained('pkg_products')->onDelete('cascade')->comment('打包产品ID');
            $table->foreignId('hotel_id')->constrained('res_hotels')->onDelete('restrict')->comment('酒店ID');
            $table->foreignId('room_type_id')->constrained('res_room_types')->onDelete('restrict')->comment('房型ID');
            $table->date('biz_date')->comment('业务日期');
            $table->decimal('sale_price', 10, 2)->comment('推给OTA的总价');
            $table->decimal('cost_price', 10, 2)->comment('总成本');
            $table->string('composite_code', 255)->comment('格式：PKG|RoomID|HotelID|ProductID');
            $table->timestamp('last_updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间（用于判断是否需要重新推送）');
            $table->timestamps();
            
            $table->unique(['pkg_product_id', 'room_type_id', 'biz_date'], 'uk_prod_room_date');
            $table->index('composite_code', 'idx_composite');
            $table->index('pkg_product_id', 'idx_product');
            $table->index('biz_date', 'idx_date');
            $table->index(['hotel_id', 'room_type_id'], 'idx_hotel_room');
            $table->index('last_updated_at', 'idx_last_updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pkg_product_daily_prices');
    }
};
