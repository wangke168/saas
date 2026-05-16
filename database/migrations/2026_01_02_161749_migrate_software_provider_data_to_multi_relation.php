<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 迁移现有数据：将 scenic_spots.software_provider_id 迁移到中间表
     * 迁移产品数据：从景区获取服务商ID
     */
    public function up(): void
    {
        // 步骤1：迁移现有数据：将 scenic_spots.software_provider_id 迁移到中间表
        // 注意：根据确认，目前数据中不存在现有景区有多个服务商的情况，所以迁移很简单
        DB::statement("
            INSERT INTO scenic_spot_software_providers (scenic_spot_id, software_provider_id, created_at, updated_at)
            SELECT id, software_provider_id, NOW(), NOW()
            FROM scenic_spots
            WHERE software_provider_id IS NOT NULL
        ");

        // 步骤2：迁移产品数据：从景区获取服务商ID
        // 由于每个景区目前只有一个服务商，可以直接迁移
        DB::statement("
            UPDATE products p
            INNER JOIN scenic_spots ss ON p.scenic_spot_id = ss.id
            SET p.software_provider_id = ss.software_provider_id
            WHERE ss.software_provider_id IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚：清空中间表数据
        DB::table('scenic_spot_software_providers')->truncate();
        
        // 回滚：清空产品的服务商ID
        DB::table('products')->update(['software_provider_id' => null]);
    }
};
