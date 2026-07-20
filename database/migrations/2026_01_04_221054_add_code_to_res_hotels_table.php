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
        if (Schema::hasColumn('res_hotels', 'code')) {
            return;
        }

        Schema::table('res_hotels', function (Blueprint $table) {
            $table->string('code', 50)->unique()->nullable()->after('name')->comment('酒店编码（系统自动生成：H + 5位数字）');
            $table->index('code', 'idx_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('res_hotels', function (Blueprint $table) {
            $table->dropIndex('idx_code');
            $table->dropColumn('code');
        });
    }
};
