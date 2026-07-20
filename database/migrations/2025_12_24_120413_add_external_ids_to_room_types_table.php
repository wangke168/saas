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
        Schema::table('room_types', function (Blueprint $table) {
            $table->string('external_id', 255)->nullable()->after('code')->comment('系统服务商中的房型ID（字符串，可选）');
            $table->string('external_code', 255)->nullable()->after('external_id')->comment('系统服务商中的房型编码（字符串，可选）');
            // 索引名带表前缀，避免与 hotels 表的 idx_external_id 在 sqlite（索引名全局唯一）下冲突
            $table->index('external_id', 'idx_room_types_external_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropIndex('idx_room_types_external_id');
            $table->dropColumn(['external_id', 'external_code']);
        });
    }
};
