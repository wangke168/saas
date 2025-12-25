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
        // 先删除外键约束
        if (Schema::hasColumn('hotels', 'resource_provider_id')) {
            Schema::table('hotels', function (Blueprint $table) {
                $table->dropForeign(['resource_provider_id']);
            });
        }

        Schema::table('hotels', function (Blueprint $table) {
            // 移除冗余字段
            if (Schema::hasColumn('hotels', 'resource_provider_id')) {
                $table->dropColumn('resource_provider_id');
            }
            
            // 添加外部ID字段
            $table->string('external_id', 255)->nullable()->after('code')->comment('系统服务商中的酒店ID（字符串，可选）');
            $table->string('external_code', 255)->nullable()->after('external_id')->comment('系统服务商中的酒店编码（字符串，可选）');
            $table->index('external_id', 'idx_external_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropIndex('idx_external_id');
            $table->dropColumn(['external_id', 'external_code']);
            
            // 恢复 resource_provider_id 字段（如果需要）
            $table->unsignedBigInteger('resource_provider_id')->nullable()->after('is_connected')->comment('资源方ID');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->foreign('resource_provider_id')
                ->references('id')
                ->on('resource_providers')
                ->onDelete('set null');
        });
    }
};
