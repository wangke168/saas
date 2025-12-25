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
        Schema::table('scenic_spots', function (Blueprint $table) {
            $table->unsignedBigInteger('resource_config_id')->nullable()->after('software_provider_id')->comment('系统配置ID（仅直连时使用）');
            $table->boolean('is_system_connected')->default(false)->after('resource_config_id')->comment('是否支持系统直连');
            $table->index('resource_config_id', 'idx_resource_config_id');
        });

        // 添加外键约束
        Schema::table('scenic_spots', function (Blueprint $table) {
            $table->foreign('resource_config_id', 'scenic_spots_resource_config_id_foreign')
                ->references('id')
                ->on('resource_configs')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scenic_spots', function (Blueprint $table) {
            $table->dropForeign('scenic_spots_resource_config_id_foreign');
            $table->dropIndex('idx_resource_config_id');
            $table->dropColumn(['resource_config_id', 'is_system_connected']);
        });
    }
};
