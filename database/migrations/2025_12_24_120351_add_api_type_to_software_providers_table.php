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
        Schema::table('software_providers', function (Blueprint $table) {
            $table->string('api_type')->nullable()->after('code')->comment('接口类型: hengdian=横店, other=其他');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('software_providers', function (Blueprint $table) {
            $table->dropColumn('api_type');
        });
    }
};
