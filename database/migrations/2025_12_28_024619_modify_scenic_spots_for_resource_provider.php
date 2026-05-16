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
        // 添加 resource_provider_id 字段（可选，用于快速查询）
        if (!Schema::hasColumn('scenic_spots', 'resource_provider_id')) {
            Schema::table('scenic_spots', function (Blueprint $table) {
                $table->foreignId('resource_provider_id')->nullable()->after('software_provider_id')
                    ->constrained('resource_providers')->nullOnDelete();
                $table->index('resource_provider_id');
            });
        }

        // 修改 code 字段为可空（因为要自动生成）
        Schema::table('scenic_spots', function (Blueprint $table) {
            $table->string('code')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('scenic_spots', 'resource_provider_id')) {
            Schema::table('scenic_spots', function (Blueprint $table) {
                $table->dropForeign(['resource_provider_id']);
                $table->dropIndex(['resource_provider_id']);
                $table->dropColumn('resource_provider_id');
            });
        }

        // 恢复 code 字段为必填（如果需要）
        Schema::table('scenic_spots', function (Blueprint $table) {
            $table->string('code')->nullable(false)->change();
        });
    }
};
