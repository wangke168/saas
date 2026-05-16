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
        Schema::table('res_room_types', function (Blueprint $table) {
            $table->string('code', 6)->unique()->nullable()->after('id')->comment('房型编码（6位：RR + 4位数字）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('res_room_types', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};




