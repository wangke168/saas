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
        Schema::table('ota_products', function (Blueprint $table) {
            // 推送状态：pending（待处理）、processing（处理中）、success（成功）、failed（失败）
            $table->string('push_status', 20)->nullable()->after('pushed_at')->comment('推送状态');
            // 推送开始时间
            $table->timestamp('push_started_at')->nullable()->after('push_status')->comment('推送开始时间');
            // 推送完成时间
            $table->timestamp('push_completed_at')->nullable()->after('push_started_at')->comment('推送完成时间');
            // 推送消息（成功或失败的原因）
            $table->text('push_message')->nullable()->after('push_completed_at')->comment('推送消息');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ota_products', function (Blueprint $table) {
            $table->dropColumn(['push_status', 'push_started_at', 'push_completed_at', 'push_message']);
        });
    }
};