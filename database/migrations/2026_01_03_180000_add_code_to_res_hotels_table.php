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




