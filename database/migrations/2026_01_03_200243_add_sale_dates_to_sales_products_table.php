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
        Schema::table('sales_products', function (Blueprint $table) {
            // 先添加可为空的字段
            $table->date('sale_start_date')->nullable()->after('stay_days')->comment('销售开始日期');
            $table->date('sale_end_date')->nullable()->after('sale_start_date')->comment('销售结束日期');
        });
        
        // 为现有数据设置默认值（从今天开始，一年后结束）
        DB::table('sales_products')
            ->whereNull('sale_start_date')
            ->update([
                'sale_start_date' => now()->format('Y-m-d'),
                'sale_end_date' => now()->addYear()->format('Y-m-d'),
            ]);
        
        // 然后将字段改为必填
        Schema::table('sales_products', function (Blueprint $table) {
            $table->date('sale_start_date')->nullable(false)->change();
            $table->date('sale_end_date')->nullable(false)->change();
            
            $table->index('sale_start_date');
            $table->index('sale_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_products', function (Blueprint $table) {
            $table->dropIndex(['sale_start_date']);
            $table->dropIndex(['sale_end_date']);
            $table->dropColumn(['sale_start_date', 'sale_end_date']);
        });
    }
};
