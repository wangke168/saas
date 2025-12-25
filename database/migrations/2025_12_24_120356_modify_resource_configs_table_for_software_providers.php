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
        // 先删除旧的外键和唯一约束
        Schema::table('resource_configs', function (Blueprint $table) {
            if (Schema::hasColumn('resource_configs', 'resource_provider_id')) {
                $table->dropForeign(['resource_provider_id']);
                $table->dropUnique(['resource_provider_id']);
            }
        });

        // 添加新字段
        Schema::table('resource_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('software_provider_id')->nullable()->after('id')->comment('系统服务商ID');
            $table->unsignedBigInteger('scenic_spot_id')->nullable()->after('software_provider_id')->comment('景区ID（必须，每个景区单独配置）');
        });

        // 迁移数据（如果 resource_provider_id 存在）
        if (Schema::hasColumn('resource_configs', 'resource_provider_id')) {
            DB::statement('UPDATE resource_configs SET software_provider_id = resource_provider_id WHERE resource_provider_id IS NOT NULL');
        }

        // 删除旧字段
        Schema::table('resource_configs', function (Blueprint $table) {
            if (Schema::hasColumn('resource_configs', 'resource_provider_id')) {
                $table->dropColumn('resource_provider_id');
            }
        });

        // 添加新的外键约束
        Schema::table('resource_configs', function (Blueprint $table) {
            $table->foreign('software_provider_id', 'resource_configs_software_provider_id_foreign')
                ->references('id')
                ->on('software_providers')
                ->onDelete('cascade');
            
            $table->foreign('scenic_spot_id', 'resource_configs_scenic_spot_id_foreign')
                ->references('id')
                ->on('scenic_spots')
                ->onDelete('cascade');
            
            // 唯一约束：一个景区在一个系统服务商下只有一个配置
            $table->unique(['software_provider_id', 'scenic_spot_id'], 'unique_software_provider_scenic_spot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_configs', function (Blueprint $table) {
            $table->dropForeign('resource_configs_software_provider_id_foreign');
            $table->dropForeign('resource_configs_scenic_spot_id_foreign');
            $table->dropUnique('unique_software_provider_scenic_spot');
        });

        Schema::table('resource_configs', function (Blueprint $table) {
            $table->dropColumn('scenic_spot_id');
            $table->renameColumn('software_provider_id', 'resource_provider_id');
        });

        Schema::table('resource_configs', function (Blueprint $table) {
            $table->foreign('resource_provider_id')
                ->references('id')
                ->on('resource_providers')
                ->onDelete('cascade');
            $table->unique('resource_provider_id');
        });
    }
};
