<?php

namespace App\Services;

use App\Models\Price;
use App\Models\PriceRule;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProductService
{
    /**
     * 获取产品价格分组分页数据（按房型分组）
     */
    public function getPriceGroups(Product $product, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 10)));

        $baseQuery = Price::query()->where('product_id', $product->id);

        if (! empty($filters['room_type_id'])) {
            $baseQuery->where('room_type_id', (int) $filters['room_type_id']);
        }

        if (! empty($filters['start_date'])) {
            $baseQuery->where('date', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $baseQuery->where('date', '<=', $filters['end_date']);
        }

        $total = (clone $baseQuery)->distinct('room_type_id')->count('room_type_id');

        $roomTypeIds = (clone $baseQuery)
            ->select('room_type_id')
            ->distinct()
            ->orderBy('room_type_id')
            ->forPage($page, $perPage)
            ->pluck('room_type_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $pricesByRoomType = [];
        if (! empty($roomTypeIds)) {
            $pricesByRoomType = (clone $baseQuery)
                ->whereIn('room_type_id', $roomTypeIds)
                ->with(['roomType.hotel'])
                ->orderBy('room_type_id')
                ->orderBy('date')
                ->get()
                ->groupBy('room_type_id')
                ->toArray();
        }

        $groups = collect($roomTypeIds)->map(function (int $roomTypeId) use ($pricesByRoomType): array {
            $groupPrices = $pricesByRoomType[$roomTypeId] ?? [];
            $firstPrice = $groupPrices[0] ?? null;
            $roomType = $firstPrice['room_type'] ?? null;
            $hotel = $roomType['hotel'] ?? null;

            return [
                'room_type_id' => $roomTypeId,
                'hotel_id' => $roomType['hotel_id'] ?? null,
                'hotel_name' => $hotel['name'] ?? '未知酒店',
                'room_type_name' => $roomType['name'] ?? '未知房型',
                'prices' => collect($groupPrices)->map(function (array $price): array {
                    return [
                        'id' => $price['id'],
                        'date' => $price['date'],
                        'market_price' => $price['market_price'],
                        'settlement_price' => $price['settlement_price'],
                        'sale_price' => $price['sale_price'],
                        'source' => $price['source'],
                        'room_type_id' => $price['room_type_id'],
                    ];
                })->all(),
            ];
        })->all();

        $allPriceRoomTypeIds = Price::query()
            ->where('product_id', $product->id)
            ->select('room_type_id')
            ->distinct()
            ->pluck('room_type_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'groups' => $groups,
            'available_room_type_ids' => $allPriceRoomTypeIds,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

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

            // 方案 A：销售有效期变更后，软删除落在新区间外的日历价，与产品可售区间对齐
            $saleStart = $product->sale_start_date;
            $saleEnd = $product->sale_end_date;
            if ($saleStart !== null && $saleEnd !== null) {
                $startStr = Carbon::parse($saleStart)->toDateString();
                $endStr = Carbon::parse($saleEnd)->toDateString();
                Price::where('product_id', $product->id)
                    ->where(function ($q) use ($startStr, $endStr) {
                        $q->where('date', '<', $startStr)
                            ->orWhere('date', '>', $endStr);
                    })
                    ->delete();
            }

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

        if (! $basePrice) {
            return [
                'market_price' => 0,
                'settlement_price' => 0,
                'sale_price' => 0,
            ];
        }

        // 基础价格来自价格日历（同一天同房型的基础市场/结算/销售价）
        $baseMarketPrice = (float) $basePrice->market_price;
        $baseSettlementPrice = (float) $basePrice->settlement_price;
        $baseSalePrice = (float) $basePrice->sale_price;

        // 口径A：同一天命中多条规则时，不叠加，只选 sale_price 调整后最高的那条规则
        $bestMarketPrice = $baseMarketPrice;
        $bestSettlementPrice = $baseSettlementPrice;
        $bestSalePrice = $baseSalePrice;
        $bestRuleId = null;

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
                $candidateMarketPrice = $baseMarketPrice + (float) $rule->market_price_adjustment;
                $candidateSettlementPrice = $baseSettlementPrice + (float) $rule->settlement_price_adjustment;
                $candidateSalePrice = $baseSalePrice + (float) $rule->sale_price_adjustment;

                // 用 2 位小数做比较，避免 decimal/float 精度导致的偶发抖动
                $candidateSalePriceR = round($candidateSalePrice, 2);
                $bestSalePriceR = round($bestSalePrice, 2);
                $candidateMarketPriceR = round($candidateMarketPrice, 2);
                $bestMarketPriceR = round($bestMarketPrice, 2);

                $isBetter =
                    $candidateSalePriceR > $bestSalePriceR
                    || ($candidateSalePriceR === $bestSalePriceR && $candidateMarketPriceR > $bestMarketPriceR)
                    || ($candidateSalePriceR === $bestSalePriceR && $candidateMarketPriceR === $bestMarketPriceR && ($bestRuleId === null || ($rule->id !== null && $rule->id > $bestRuleId)));

                if ($isBetter) {
                    $bestMarketPrice = $candidateMarketPrice;
                    $bestSettlementPrice = $candidateSettlementPrice;
                    $bestSalePrice = $candidateSalePrice;
                    $bestRuleId = $rule->id;
                }
            }
        }

        return [
            'market_price' => max(0, $bestMarketPrice),
            'settlement_price' => max(0, $bestSettlementPrice),
            'sale_price' => max(0, $bestSalePrice),
        ];
    }
}
