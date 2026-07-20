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
        // 全新数据库上该迁移先于 create_res_hotels_table 执行（时间戳倒挂），此时跳过，
        // code 列由后续 2026_01_04_221054_add_code_to_res_hotels_table 补充
        if (! Schema::hasTable('res_hotels') || Schema::hasColumn('res_hotels', 'code')) {
            return;
        }

        Schema::table('res_hotels', function (Blueprint $table) {
            $table->string('code', 6)->unique()->nullable()->after('id')->comment('酒店编码（6位：RH + 4位数字）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('res_hotels', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};




