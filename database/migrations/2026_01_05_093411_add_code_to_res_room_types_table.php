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
        // code 列可能已由建表迁移或更早的 add_code 迁移创建，避免重复添加
        if (Schema::hasColumn('res_room_types', 'code')) {
            return;
        }

        Schema::table('res_room_types', function (Blueprint $table) {
            $table->string('code', 50)->unique()->nullable()->after('name')
                  ->comment('房型编码（系统自动生成：R + 5位数字）');
            // 索引名带表前缀，避免与 res_hotels 表的 idx_code 在 sqlite（索引名全局唯一）下冲突
            $table->index('code', 'idx_res_room_types_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('res_room_types', function (Blueprint $table) {
            $table->dropIndex('idx_res_room_types_code');
            $table->dropColumn('code');
        });
    }
};
