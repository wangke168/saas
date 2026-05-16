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
        Schema::table('pkg_products', function (Blueprint $table) {
            if (!Schema::hasColumn('pkg_products', 'sale_start_date')) {
                $table->date('sale_start_date')->nullable()->after('status')
                    ->comment('销售开始日期（可为空，为空表示不限制开始日期）');
            }
            if (!Schema::hasColumn('pkg_products', 'sale_end_date')) {
                $table->date('sale_end_date')->nullable()->after('sale_start_date')
                    ->comment('销售结束日期（可为空，为空表示不限制结束日期）');
            }
        });

        // 添加索引（如果不存在）
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();
        $indexes = $connection->select(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$databaseName, 'pkg_products', 'idx_sale_date_range']
        );
        
        if (count($indexes) === 0) {
            Schema::table('pkg_products', function (Blueprint $table) {
                $table->index(['sale_start_date', 'sale_end_date'], 'idx_sale_date_range');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pkg_products', function (Blueprint $table) {
            // 删除索引
            if ($this->hasIndex('pkg_products', 'idx_sale_date_range')) {
                $table->dropIndex('idx_sale_date_range');
            }
            
            // 删除字段
            if (Schema::hasColumn('pkg_products', 'sale_end_date')) {
                $table->dropColumn('sale_end_date');
            }
            if (Schema::hasColumn('pkg_products', 'sale_start_date')) {
                $table->dropColumn('sale_start_date');
            }
        });
    }

    /**
     * 检查索引是否存在
     */
    protected function hasIndex(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();
        $indexes = $connection->select(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$databaseName, $table, $index]
        );
        return count($indexes) > 0;
    }
};




