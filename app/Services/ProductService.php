<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Price;
use App\Models\PriceRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProductService
{
    /**
     * 创建产品
     */
    public function createProduct(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create($data);
            
            // 如果有初始价格，创建价格记录
            if (isset($data['prices'])) {
                foreach ($data['prices'] as $priceData) {
                    Price::create(array_merge($priceData, [
                        'product_id' => $product->id,
                    ]));
                }
            }
            
            return $product->load(['scenicSpot', 'softwareProvider', 'prices', 'priceRules']);
        });
    }

    /**
     * 更新产品
     */
    public function updateProduct(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->update($data);
            
            // 未来可以在这里添加其他逻辑，比如：
            // - 清除相关缓存
            // - 更新关联数据
            // - 发送通知等
            
            return $product->load(['scenicSpot', 'softwareProvider', 'prices', 'priceRules']);
        });
    }

    /**
     * 应用加价规则计算价格
     */
    public function calculatePrice(Product $product, int $roomTypeId, string $date): array
    {
        $basePrice = Price::where('product_id', $product->id)
            ->where('room_type_id', $roomTypeId)
            ->where('date', $date)
            ->first();

        if (!$basePrice) {
            return [
                'market_price' => 0,
                'settlement_price' => 0,
                'sale_price' => 0,
            ];
        }

        $marketPrice = $basePrice->market_price;
        $settlementPrice = $basePrice->settlement_price;
        $salePrice = $basePrice->sale_price;

        // 应用加价规则
        $rules = PriceRule::where('product_id', $product->id)
            ->where('is_active', true)
            ->whereHas('items', function ($query) use ($roomTypeId) {
                $query->where('room_type_id', $roomTypeId);
            })
            ->get();

        foreach ($rules as $rule) {
            $shouldApply = false;

            // 兼容旧数据：根据 type 字段判断
            if ($rule->type->value === 'weekday') {
                // 旧格式：只有周几规则（全时段生效）
                $weekday = date('N', strtotime($date));
                $weekdays = explode(',', $rule->weekdays ?? '');
                $shouldApply = in_array($weekday, $weekdays);
            } elseif ($rule->type->value === 'date_range') {
                // 旧格式：只有日期范围规则（范围内所有日期生效）
                $dateCarbon = Carbon::parse($date)->startOfDay();
                $startDate = Carbon::parse($rule->start_date)->startOfDay();
                $endDate = Carbon::parse($rule->end_date)->startOfDay();
                $shouldApply = $dateCarbon->gte($startDate) && $dateCarbon->lte($endDate);
            } else {
                // 新格式：统一规则（日期范围 + 周几可选）
                // 检查日期范围
                $inDateRange = true;
                if ($rule->start_date && $rule->end_date) {
                    $dateCarbon = Carbon::parse($date)->startOfDay();
                    $startDate = Carbon::parse($rule->start_date)->startOfDay();
                    $endDate = Carbon::parse($rule->end_date)->startOfDay();
                    $inDateRange = $dateCarbon->gte($startDate) && $dateCarbon->lte($endDate);
                }

                // 检查周几（如果设置了周几）
                $matchWeekday = true;
                if ($rule->weekdays) {
                    $weekday = date('N', strtotime($date));
                    $weekdays = explode(',', $rule->weekdays);
                    $matchWeekday = in_array($weekday, $weekdays);
                }

                // 同时满足日期范围和周几条件
                $shouldApply = $inDateRange && $matchWeekday;
            }

            if ($shouldApply) {
                $marketPrice += $rule->market_price_adjustment;
                $settlementPrice += $rule->settlement_price_adjustment;
                $salePrice += $rule->sale_price_adjustment;
            }
        }

        return [
            'market_price' => max(0, $marketPrice),
            'settlement_price' => max(0, $settlementPrice),
            'sale_price' => max(0, $salePrice),
        ];
    }
}

