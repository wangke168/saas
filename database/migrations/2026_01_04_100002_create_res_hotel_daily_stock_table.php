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
        Schema::create('res_hotel_daily_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('res_hotels')->onDelete('restrict')->comment('酒店ID');
            $table->foreignId('room_type_id')->constrained('res_room_types')->onDelete('restrict')->comment('房型ID');
            $table->date('biz_date')->comment('业务日期');
            
            // 价格信息
            $table->decimal('cost_price', 10, 2)->comment('结算底价（用于计算打包价）');
            $table->decimal('sale_price', 10, 2)->comment('销售价（用于计算打包价）');
            
            // 库存信息（仅自控库存有效）
            $table->integer('stock_total')->default(0)->comment('总房量');
            $table->integer('stock_sold')->default(0)->comment('已售房量');
            $table->integer('stock_available')->storedAs('stock_total - stock_sold')->comment('可用库存（计算字段）');
            
            // 乐观锁字段
            $table->integer('version')->default(0)->comment('版本号（用于乐观锁）');
            
            $table->timestamps();
            
            $table->unique(['room_type_id', 'biz_date'], 'uk_room_date');
            $table->index('hotel_id');
            $table->index('biz_date');
            $table->index('stock_available');
            $table->index('version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('res_hotel_daily_stock');
    }
};




