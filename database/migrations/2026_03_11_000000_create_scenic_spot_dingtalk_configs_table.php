<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scenic_spot_dingtalk_configs')) {
            return;
        }

        Schema::create('scenic_spot_dingtalk_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scenic_spot_id')->comment('景区ID');
            $table->text('webhook_url')->nullable()->comment('钉钉机器人Webhook地址');
            $table->boolean('enabled')->default(true)->comment('是否启用景区专属钉钉通知');
            $table->string('remark')->nullable()->comment('备注');
            $table->timestamps();

            $table->unique(['scenic_spot_id'], 'scenic_spot_dingtalk_configs_spot_unique');
            $table->foreign('scenic_spot_id')
                ->references('id')
                ->on('scenic_spots')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenic_spot_dingtalk_configs');
    }
};

