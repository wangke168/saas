<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 移除api_url字段，因为API地址现在从software_providers表获取
     */
    public function up(): void
    {
        Schema::table('resource_configs', function (Blueprint $table) {
            $table->dropColumn('api_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_configs', function (Blueprint $table) {
            $table->string('api_url')->nullable()->after('password')->comment('接口地址（已废弃，从服务商获取）');
        });
    }
};
