<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scenic_spot_ota_inventory_push_configs')) {
            return;
        }

        Schema::create('scenic_spot_ota_inventory_push_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scenic_spot_id')->comment('景区ID');
            $table->unsignedBigInteger('ota_platform_id')->comment('OTA平台ID');
            $table->unsignedInteger('push_zero_threshold')->default(0)->comment('推送到OTA时视为0的库存上限：真实库存≤此值则推0');
            $table->boolean('is_active')->default(true)->comment('是否启用本配置');
            $table->timestamps();

            $table->unique(
                ['scenic_spot_id', 'ota_platform_id'],
                'scenic_spot_ota_inventory_push_unique'
            );

            $table->foreign('scenic_spot_id')
                ->references('id')
                ->on('scenic_spots')
                ->onDelete('cascade');

            $table->foreign('ota_platform_id')
                ->references('id')
                ->on('ota_platforms')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenic_spot_ota_inventory_push_configs');
    }
};
