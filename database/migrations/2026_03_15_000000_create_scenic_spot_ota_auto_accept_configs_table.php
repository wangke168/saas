<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scenic_spot_ota_auto_accept_configs')) {
            return;
        }

        Schema::create('scenic_spot_ota_auto_accept_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scenic_spot_id')->comment('景区ID');
            $table->unsignedBigInteger('ota_platform_id')->comment('OTA平台ID');
            $table->boolean('auto_accept_when_sufficient')->default(true)->comment('库存充裕时是否自动接单');
            $table->unsignedInteger('auto_accept_stock_buffer')->default(5)->comment('库存充裕阈值缓冲：可用数量-本单数量>=此值才自动接单');
            $table->boolean('is_active')->default(true)->comment('是否启用本配置');
            $table->timestamps();

            $table->unique(
                ['scenic_spot_id', 'ota_platform_id'],
                'scenic_spot_ota_auto_accept_configs_unique'
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
        Schema::dropIfExists('scenic_spot_ota_auto_accept_configs');
    }
};

