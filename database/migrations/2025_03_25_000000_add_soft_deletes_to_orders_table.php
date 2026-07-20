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
        // 全新数据库上该迁移会先于 create_orders_table 执行（时间戳倒挂），此时跳过；
        // deleted_at 已并入 create_orders_table 迁移
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'deleted_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
