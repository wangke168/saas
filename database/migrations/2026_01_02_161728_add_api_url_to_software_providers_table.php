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
        Schema::table('software_providers', function (Blueprint $table) {
            // 添加服务商API地址字段（必填，每个服务商地址不同）
            $table->string('api_url', 255)->nullable()->after('api_type')->comment('服务商API地址（每个服务商地址不同）');
        });
        
        // 为现有数据设置默认值（如果有的话，需要根据实际情况更新）
        // 注意：这里先设为可空，等数据迁移完成后再改为必填
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('software_providers', function (Blueprint $table) {
            $table->dropColumn('api_url');
        });
    }
};
