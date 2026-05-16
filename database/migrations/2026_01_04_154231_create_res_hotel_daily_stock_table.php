<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('res_hotel_daily_stock')) {
            return;
        }
        
        Schema::create('res_hotel_daily_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('res_hotels')->onDelete('restrict')->comment('酒店ID');
            $table->foreignId('room_type_id')->constrained('res_room_types')->onDelete('restrict')->comment('房型ID');
            $table->date('biz_date')->comment('业务日期');
            $table->decimal('cost_price', 10, 2)->comment('结算底价');
            $table->decimal('sale_price', 10, 2)->comment('销售价');
            $table->integer('stock_total')->default(0)->comment('总房量（仅自控库存有效）');
            $table->integer('stock_sold')->default(0)->comment('已售房量（仅自控库存有效）');
            $table->integer('version')->default(0)->comment('版本号（用于乐观锁）');
            $table->timestamps();
            
            $table->unique(['room_type_id', 'biz_date'], 'uk_room_date');
            $table->index('hotel_id', 'idx_hotel');
            $table->index('biz_date', 'idx_date');
            $table->index('version', 'idx_version');
        });
        
        // 添加计算字段（可用库存）
        DB::statement('ALTER TABLE res_hotel_daily_stock ADD COLUMN stock_available INT GENERATED ALWAYS AS (stock_total - stock_sold) STORED COMMENT \'可用库存\'');
        DB::statement('ALTER TABLE res_hotel_daily_stock ADD INDEX idx_available (stock_available)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('res_hotel_daily_stock');
    }
};
