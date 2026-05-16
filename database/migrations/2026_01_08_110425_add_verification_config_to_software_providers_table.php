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
            $table->json('verification_config')->nullable()->after('description')->comment('核销状态同步配置');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('software_providers', function (Blueprint $table) {
            $table->dropColumn('verification_config');
        });
    }
};
