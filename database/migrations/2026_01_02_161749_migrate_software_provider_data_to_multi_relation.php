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
        // 使用查询构造器改写（原写法依赖 MySQL 特有的 NOW() 与 UPDATE ... INNER JOIN，
        // 在 sqlite 测试环境下无法执行；全新数据库上本迁移无数据可回填）
        $now = now();

        // 步骤1：迁移现有数据：将 scenic_spots.software_provider_id 迁移到中间表
        // 注意：根据确认，目前数据中不存在现有景区有多个服务商的情况，所以迁移很简单
        $spots = DB::table('scenic_spots')
            ->whereNotNull('software_provider_id')
            ->get(['id', 'software_provider_id']);

        $rows = $spots->map(fn (object $spot): array => [
            'scenic_spot_id' => $spot->id,
            'software_provider_id' => $spot->software_provider_id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            DB::table('scenic_spot_software_providers')->insert($rows);
        }

        // 步骤2：迁移产品数据：从景区获取服务商ID
        // 由于每个景区目前只有一个服务商，可以直接迁移
        $spots->each(function (object $spot): void {
            DB::table('products')
                ->where('scenic_spot_id', $spot->id)
                ->update(['software_provider_id' => $spot->software_provider_id]);
        });
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
