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
        Schema::table('res_hotel_daily_stock', function (Blueprint $table) {
            if (!Schema::hasColumn('res_hotel_daily_stock', 'is_closed')) {
                $table->boolean('is_closed')->default(false)->after('version')->comment('是否关闭（人工关闭）');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('res_hotel_daily_stock', function (Blueprint $table) {
            if (Schema::hasColumn('res_hotel_daily_stock', 'is_closed')) {
                $table->dropColumn('is_closed');
            }
        });
    }
};




