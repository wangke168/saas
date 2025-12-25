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
        if (Schema::hasTable('resource_sync_logs')) {
            return;
        }

        Schema::create('resource_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('software_provider_id')->comment('系统服务商ID');
            $table->unsignedBigInteger('scenic_spot_id')->comment('景区ID');
            $table->string('sync_type', 50)->comment('同步类型: inventory=库存, price=价格');
            $table->string('sync_mode', 50)->comment('同步模式: push=推送, pull=抓取, manual=手工');
            $table->string('status', 50)->comment('状态: success=成功, failed=失败, pending=进行中');
            $table->text('message')->nullable()->comment('同步消息或错误信息');
            $table->integer('synced_count')->default(0)->comment('同步数量');
            $table->timestamp('last_synced_at')->nullable()->comment('最后同步时间');
            $table->timestamps();

            $table->index('software_provider_id', 'idx_software_provider_id');
            $table->index('scenic_spot_id', 'idx_scenic_spot_id');
            $table->index('sync_type', 'idx_sync_type');
            $table->index('last_synced_at', 'idx_last_synced_at');

            $table->foreign('software_provider_id', 'resource_sync_logs_software_provider_id_foreign')
                ->references('id')
                ->on('software_providers')
                ->onDelete('cascade');
            
            $table->foreign('scenic_spot_id', 'resource_sync_logs_scenic_spot_id_foreign')
                ->references('id')
                ->on('scenic_spots')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_sync_logs');
    }
};
