<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Price;
use App\Models\PriceRule;
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
            
            return $product->load(['scenicSpot', 'prices', 'priceRules']);
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
            
            return $product->load(['scenicSpot', 'prices', 'priceRules']);
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

            if ($rule->type->value === 'weekday') {
                $weekday = date('N', strtotime($date));
                $weekdays = explode(',', $rule->weekdays ?? '');
                $shouldApply = in_array($weekday, $weekdays);
            } elseif ($rule->type->value === 'date_range') {
                $shouldApply = $date >= $rule->start_date && $date <= $rule->end_date;
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

