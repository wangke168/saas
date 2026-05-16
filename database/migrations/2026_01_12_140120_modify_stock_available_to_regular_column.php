<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * 将 stock_available 从生成列改为普通字段，允许直接编辑
     */
    public function up(): void
    {
        // 检查表是否存在
        if (!Schema::hasTable('res_hotel_daily_stock')) {
            return;
        }

        // 检查 stock_available 字段是否存在
        if (!Schema::hasColumn('res_hotel_daily_stock', 'stock_available')) {
            // 如果不存在，直接添加普通字段
            Schema::table('res_hotel_daily_stock', function (Blueprint $table) {
                $table->integer('stock_available')->nullable()->after('stock_sold')->comment('可用库存');
            });
            
            // 填充现有数据
            DB::statement('UPDATE res_hotel_daily_stock SET stock_available = stock_total - stock_sold WHERE stock_available IS NULL');
        } else {
            // 如果存在，检查是否是生成列
            $columnInfo = DB::select("
                SELECT COLUMN_TYPE, EXTRA 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'res_hotel_daily_stock' 
                AND COLUMN_NAME = 'stock_available'
            ");
            
            if (!empty($columnInfo)) {
                $extra = $columnInfo[0]->EXTRA ?? '';
                
                // 如果是生成列（VIRTUAL 或 STORED），需要删除并重新创建
                if (str_contains($extra, 'STORED') || str_contains($extra, 'VIRTUAL')) {
                    // 先备份现有数据
                    DB::statement('UPDATE res_hotel_daily_stock SET stock_available = stock_total - stock_sold WHERE stock_available IS NULL');
                    
                    // 删除生成列
                    DB::statement('ALTER TABLE res_hotel_daily_stock DROP COLUMN stock_available');
                    DB::statement('ALTER TABLE res_hotel_daily_stock DROP INDEX IF EXISTS idx_available');
                    
                    // 添加普通字段
                    Schema::table('res_hotel_daily_stock', function (Blueprint $table) {
                        $table->integer('stock_available')->nullable()->after('stock_sold')->comment('可用库存');
                    });
                    
                    // 填充数据
                    DB::statement('UPDATE res_hotel_daily_stock SET stock_available = stock_total - stock_sold WHERE stock_available IS NULL');
                    
                    // 重新创建索引
                    DB::statement('ALTER TABLE res_hotel_daily_stock ADD INDEX idx_available (stock_available)');
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('res_hotel_daily_stock')) {
            return;
        }

        // 检查 stock_available 字段是否存在
        if (Schema::hasColumn('res_hotel_daily_stock', 'stock_available')) {
            // 删除普通字段
            Schema::table('res_hotel_daily_stock', function (Blueprint $table) {
                $table->dropColumn('stock_available');
            });
            
            // 删除索引
            DB::statement('ALTER TABLE res_hotel_daily_stock DROP INDEX IF EXISTS idx_available');
            
            // 重新创建生成列
            DB::statement('ALTER TABLE res_hotel_daily_stock ADD COLUMN stock_available INT GENERATED ALWAYS AS (stock_total - stock_sold) STORED COMMENT \'可用库存\'');
            DB::statement('ALTER TABLE res_hotel_daily_stock ADD INDEX idx_available (stock_available)');
        }
    }
};



