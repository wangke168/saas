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
            $table->string('source', 20)->default('manual')->after('version')
                  ->comment('数据来源：manual=手工维护, api=PMS推送');
            $table->index('source', 'idx_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('res_hotel_daily_stock', function (Blueprint $table) {
            $table->dropIndex('idx_source');
            $table->dropColumn('source');
        });
    }
};
