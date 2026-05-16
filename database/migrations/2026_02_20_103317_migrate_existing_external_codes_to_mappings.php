<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 将现有产品的 external_code 迁移为默认的时间段映射
     * 如果产品有 sale_start_date 和 sale_end_date，使用该日期范围
     * 否则使用一个很大的日期范围（2000-01-01 到 2099-12-31）
     */
    public function up(): void
    {
        // 检查表是否存在
        if (!Schema::hasTable('product_external_code_mappings')) {
            return;
        }

        // 查找所有有 external_code 的产品
        $products = DB::table('products')
            ->whereNotNull('external_code')
            ->where('external_code', '!=', '')
            ->get();

        $insertData = [];
        $now = Carbon::now();

        foreach ($products as $product) {
            // 确定日期范围
            $startDate = $product->sale_start_date ?? '2000-01-01';
            $endDate = $product->sale_end_date ?? '2099-12-31';

            // 确保日期格式正确
            try {
                $startDateObj = Carbon::parse($startDate);
                $endDateObj = Carbon::parse($endDate);
            } catch (\Exception $e) {
                // 如果日期解析失败，使用默认值
                $startDate = '2000-01-01';
                $endDate = '2099-12-31';
            }

            // 检查该产品是否已经有映射（避免重复）
            $existingMapping = DB::table('product_external_code_mappings')
                ->where('product_id', $product->id)
                ->where('external_code', $product->external_code)
                ->where('start_date', $startDate)
                ->where('end_date', $endDate)
                ->first();

            if (!$existingMapping) {
                $insertData[] = [
                    'product_id' => $product->id,
                    'external_code' => $product->external_code,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // 批量插入
        if (!empty($insertData)) {
            // 分批插入，避免一次性插入过多数据
            $chunks = array_chunk($insertData, 100);
            foreach ($chunks as $chunk) {
                DB::table('product_external_code_mappings')->insert($chunk);
            }
        }
    }

    /**
     * Reverse the migrations.
     * 回滚：删除由迁移创建的映射（只删除全时间段的映射）
     */
    public function down(): void
    {
        if (!Schema::hasTable('product_external_code_mappings')) {
            return;
        }

        // 删除全时间段的映射（2000-01-01 到 2099-12-31）
        DB::table('product_external_code_mappings')
            ->where('start_date', '2000-01-01')
            ->where('end_date', '2099-12-31')
            ->delete();

        // 删除使用产品销售日期的映射
        DB::statement("
            DELETE pem FROM product_external_code_mappings pem
            INNER JOIN products p ON pem.product_id = p.id
            WHERE pem.start_date = COALESCE(p.sale_start_date, '2000-01-01')
              AND pem.end_date = COALESCE(p.sale_end_date, '2099-12-31')
              AND pem.external_code = p.external_code
        ");
    }
};
