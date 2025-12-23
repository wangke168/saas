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
        // 为 hotels 表添加软删除
        if (Schema::hasTable('hotels') && !Schema::hasColumn('hotels', 'deleted_at')) {
            Schema::table('hotels', function (Blueprint $table) {
                $table->softDeletes()->after('updated_at');
            });
        }

        // 为 room_types 表添加软删除
        if (Schema::hasTable('room_types') && !Schema::hasColumn('room_types', 'deleted_at')) {
            Schema::table('room_types', function (Blueprint $table) {
                $table->softDeletes()->after('updated_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 移除 hotels 表的软删除
        if (Schema::hasTable('hotels') && Schema::hasColumn('hotels', 'deleted_at')) {
            Schema::table('hotels', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        // 移除 room_types 表的软删除
        if (Schema::hasTable('room_types') && Schema::hasColumn('room_types', 'deleted_at')) {
            Schema::table('room_types', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
