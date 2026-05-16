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
        Schema::table('resource_configs', function (Blueprint $table) {
            $table->string('service_type', 50)
                ->default('hotel')
                ->after('id')
                ->comment('服务类型: hotel=酒店系统, ticket=门票系统');
        });

        // 迁移现有数据：将所有现有配置设置为'hotel'类型
        DB::table('resource_configs')->update(['service_type' => 'hotel']);

        // 添加索引
        Schema::table('resource_configs', function (Blueprint $table) {
            $table->index('service_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_configs', function (Blueprint $table) {
            $table->dropIndex(['service_type']);
            $table->dropColumn('service_type');
        });
    }
};

