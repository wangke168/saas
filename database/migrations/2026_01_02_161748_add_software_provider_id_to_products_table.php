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
        Schema::table('products', function (Blueprint $table) {
            // 添加软件服务商ID字段（先设为可空，等数据迁移完成后再改为必填）
            $table->unsignedBigInteger('software_provider_id')->nullable()->after('scenic_spot_id')->comment('软件服务商ID（必填）');
            
            // 添加索引
            $table->index('software_provider_id', 'idx_products_software_provider_id');
            
            // 添加外键约束（先设为可空，等数据迁移完成后再改为 RESTRICT）
            $table->foreign('software_provider_id', 'products_software_provider_id_foreign')
                  ->references('id')
                  ->on('software_providers')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // 删除外键约束
            $table->dropForeign('products_software_provider_id_foreign');
            
            // 删除索引
            $table->dropIndex('idx_products_software_provider_id');
            
            // 删除字段
            $table->dropColumn('software_provider_id');
        });
    }
};
