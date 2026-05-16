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
        Schema::table('products', function (Blueprint $table) {
            $table->enum('product_type', ['ticket', 'hotel', 'package'])
                ->default('hotel')
                ->after('is_active')
                ->comment('产品类型: ticket=门票, hotel=酒店, package=打包产品');
        });

        // 迁移现有数据：将所有现有产品设置为'hotel'类型
        DB::table('products')->update(['product_type' => 'hotel']);

        // 添加索引优化查询性能
        Schema::table('products', function (Blueprint $table) {
            $table->index('product_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['product_type']);
            $table->dropColumn('product_type');
        });
    }
};




