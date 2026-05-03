<?php

namespace App\Services;

use App\Jobs\PushProductToOtaJob;
use App\Models\Price;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductExternalCodeMapping;
use App\Models\ProductUnavailablePeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    /**
     * 批量计算指定月份和房型的日历 OTA 价格。
     *
     * @param  list<int>  $roomTypeIds
     * @return list<array{
     *   id:int,
     *   date:string,
     *   room_type_id:int,
     *   market_price:float,
     *   settlement_price:float,
     *   sale_price:float,
     *   base_market_price:float,
     *   base_settlement_price:float,
     *   base_sale_price:float,
     *   source:string
     * }>
     */
    public function getCalendarOtaPrices(Product $product, int $year, int $month, array $roomTypeIds): array
    {
        $startDate = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $endDate = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $normalizedRoomTypeIds = collect($roomTypeIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $basePrices = Price::query()
            ->where('product_id', $product->id)
            ->whereIn('room_type_id', $normalizedRoomTypeIds->all())
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->orderBy('room_type_id')
            ->get(['id', 'date', 'room_type_id', 'market_price', 'settlement_price', 'sale_price', 'source']);

        if ($basePrices->isEmpty()) {
            return [];
        }

        $rulesByRoomType = PriceRule::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->whereHas('items', function ($query) use ($normalizedRoomTypeIds): void {
                $query->whereIn('room_type_id', $normalizedRoomTypeIds->all());
            })
            ->with(['items' => function ($query) use ($normalizedRoomTypeIds): void {
                $query->whereIn('room_type_id', $normalizedRoomTypeIds->all())->select(['id', 'price_rule_id', 'room_type_id']);
            }])
            ->get();

        $rulesByRoomTypeId = [];
        foreach ($rulesByRoomType as $rule) {
            foreach ($rule->items as $item) {
                $roomTypeId = (int) $item->room_type_id;
                $rulesByRoomTypeId[$roomTypeId] ??= [];
                $rulesByRoomTypeId[$roomTypeId][] = $rule;
            }
        }

        return $basePrices->map(function (Price $basePrice) use ($rulesByRoomTypeId): array {
            $roomTypeId = (int) $basePrice->room_type_id;
            $dateStr = Carbon::parse($basePrice->date)->toDateString();
            $baseMarketPrice = (float) $basePrice->market_price;
            $baseSettlementPrice = (float) $basePrice->settlement_price;
            $baseSalePrice = (float) $basePrice->sale_price;

            $bestMarketPrice = $baseMarketPrice;
            $bestSettlementPrice = $baseSettlementPrice;
            $bestSalePrice = $baseSalePrice;
            $bestRuleId = null;

            foreach ($rulesByRoomTypeId[$roomTypeId] ?? [] as $rule) {
                if (! $this->shouldApplyPriceRule($rule, $dateStr)) {
                    continue;
                }

                $candidateMarketPrice = $baseMarketPrice + (float) $rule->market_price_adjustment;
                $candidateSettlementPrice = $baseSettlementPrice + (float) $rule->settlement_price_adjustment;
                $candidateSalePrice = $baseSalePrice + (float) $rule->sale_price_adjustment;

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

            return [
                'id' => $basePrice->id,
                'date' => $dateStr,
                'room_type_id' => $roomTypeId,
                'market_price' => max(0, $bestMarketPrice),
                'settlement_price' => max(0, $bestSettlementPrice),
                'sale_price' => max(0, $bestSalePrice),
                'base_market_price' => $baseMarketPrice,
                'base_settlement_price' => $baseSettlementPrice,
                'base_sale_price' => $baseSalePrice,
                'source' => $basePrice->source instanceof \BackedEnum ? $basePrice->source->value : $basePrice->source,
            ];
        })->values()->all();
    }

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
            $periods = $data['unavailable_periods'] ?? null;
            unset($data['unavailable_periods']);

            $product = Product::create($data);

            if (is_array($periods)) {
                $this->replaceUnavailablePeriods($product, $periods);
            }

            // 如果有初始价格，创建价格记录
            if (isset($data['prices'])) {
                foreach ($data['prices'] as $priceData) {
                    Price::create(array_merge($priceData, [
                        'product_id' => $product->id,
                    ]));
                }
            }

            return $product->load(['scenicSpot', 'softwareProvider', 'prices', 'priceRules', 'unavailablePeriods']);
        });
    }

    /**
     * 更新产品
     */
    public function updateProduct(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $periods = null;
            if (array_key_exists('unavailable_periods', $data)) {
                $periods = $data['unavailable_periods'];
                unset($data['unavailable_periods']);
            }

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

            if (is_array($periods)) {
                $this->replaceUnavailablePeriods($product, $periods);
                $this->scheduleOtaResyncForProduct($product->fresh());
            }

            return $product->load(['scenicSpot', 'softwareProvider', 'prices', 'priceRules', 'unavailablePeriods']);
        });
    }

    /**
     * 复制产品及其关联数据（价格、加价规则、不可订时段、外部编码映射）
     */
    public function duplicateProduct(Product $sourceProduct, array $data): Product
    {
        return DB::transaction(function () use ($sourceProduct, $data) {
            $sourceProduct->load([
                'prices',
                'priceRules.items',
                'unavailablePeriods',
                'externalCodeMappings',
            ]);

            $periods = null;
            if (array_key_exists('unavailable_periods', $data)) {
                $periods = $data['unavailable_periods'];
                unset($data['unavailable_periods']);
            }

            // code 由模型自动生成，复制时始终重建
            unset($data['code']);
            $newProduct = Product::create($data);

            // 复制价格（房型关联通过 room_type_id 一并复制）
            if ($sourceProduct->prices->isNotEmpty()) {
                $priceRows = $sourceProduct->prices->map(function (Price $price) use ($newProduct): array {
                    $source = $price->source instanceof \BackedEnum ? $price->source->value : $price->source;

                    return [
                        'product_id' => $newProduct->id,
                        'room_type_id' => $price->room_type_id,
                        'date' => $price->date?->toDateString(),
                        'market_price' => $price->market_price,
                        'settlement_price' => $price->settlement_price,
                        'sale_price' => $price->sale_price,
                        'source' => $source,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })->all();
                Price::insert($priceRows);
            }

            // 复制加价规则及规则明细
            foreach ($sourceProduct->priceRules as $rule) {
                $newRule = PriceRule::create([
                    'product_id' => $newProduct->id,
                    'name' => $rule->name,
                    'type' => $rule->type instanceof \BackedEnum ? $rule->type->value : $rule->type,
                    'weekdays' => $rule->weekdays,
                    'start_date' => $rule->start_date?->toDateString(),
                    'end_date' => $rule->end_date?->toDateString(),
                    'market_price_adjustment' => $rule->market_price_adjustment,
                    'settlement_price_adjustment' => $rule->settlement_price_adjustment,
                    'sale_price_adjustment' => $rule->sale_price_adjustment,
                    'is_active' => $rule->is_active,
                ]);

                if ($rule->items->isNotEmpty()) {
                    $items = $rule->items->map(function ($item) use ($newRule): array {
                        return [
                            'price_rule_id' => $newRule->id,
                            'hotel_id' => $item->hotel_id,
                            'room_type_id' => $item->room_type_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    })->all();

                    DB::table('price_rule_items')->insert($items);
                }
            }

            // 如果前端传了不可订时段，使用输入值；否则复制源产品值
            if (is_array($periods)) {
                $this->replaceUnavailablePeriods($newProduct, $periods);
            } elseif ($sourceProduct->unavailablePeriods->isNotEmpty()) {
                foreach ($sourceProduct->unavailablePeriods as $period) {
                    ProductUnavailablePeriod::create([
                        'product_id' => $newProduct->id,
                        'start_date' => $period->start_date?->toDateString(),
                        'end_date' => $period->end_date?->toDateString(),
                        'note' => $period->note,
                    ]);
                }
            }

            // 复制外部编码映射
            if ($sourceProduct->externalCodeMappings->isNotEmpty()) {
                foreach ($sourceProduct->externalCodeMappings as $mapping) {
                    ProductExternalCodeMapping::create([
                        'product_id' => $newProduct->id,
                        'external_code' => $mapping->external_code,
                        'start_date' => $mapping->start_date?->toDateString(),
                        'end_date' => $mapping->end_date?->toDateString(),
                        'is_active' => $mapping->is_active,
                        'sort_order' => $mapping->sort_order,
                    ]);
                }
            }

            return $newProduct->load(['scenicSpot', 'softwareProvider', 'prices', 'priceRules.items', 'unavailablePeriods']);
        });
    }

    /**
     * @param  list<array{start_date?: mixed, end_date?: mixed, note?: mixed}>  $periods
     */
    private function replaceUnavailablePeriods(Product $product, array $periods): void
    {
        foreach ($periods as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'unavailable_periods.'.$index => ['每段不可订时间格式无效'],
                ]);
            }
            $start = $row['start_date'] ?? null;
            $end = $row['end_date'] ?? null;
            if ($start === null || $start === '' || $end === null || $end === '') {
                throw ValidationException::withMessages([
                    'unavailable_periods.'.$index => ['每段不可订时间需填写开始、结束日期'],
                ]);
            }
            if (Carbon::parse($end)->lt(Carbon::parse($start))) {
                throw ValidationException::withMessages([
                    'unavailable_periods.'.$index => ['结束日期不能早于开始日期'],
                ]);
            }
        }

        ProductUnavailablePeriod::where('product_id', $product->id)->delete();
        foreach ($periods as $row) {
            ProductUnavailablePeriod::create([
                'product_id' => $product->id,
                'start_date' => Carbon::parse($row['start_date'])->toDateString(),
                'end_date' => Carbon::parse($row['end_date'])->toDateString(),
                'note' => isset($row['note']) && $row['note'] !== '' ? (string) $row['note'] : null,
            ]);
        }
    }

    private function scheduleOtaResyncForProduct(Product $product): void
    {
        $product->load(['otaProducts' => function ($q): void {
            $q->where('is_active', true)->whereNotNull('pushed_at');
        }]);
        foreach ($product->otaProducts as $otaProduct) {
            PushProductToOtaJob::dispatch($otaProduct->id)->onQueue('ota-push');
        }
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
            if ($this->shouldApplyPriceRule($rule, $date)) {
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

    private function shouldApplyPriceRule(PriceRule $rule, string $date): bool
    {
        $type = $rule->type instanceof \BackedEnum ? $rule->type->value : (string) $rule->type;

        // 兼容旧数据：根据 type 字段判断
        if ($type === 'weekday') {
            $weekday = date('N', strtotime($date));
            $weekdays = explode(',', $rule->weekdays ?? '');

            return in_array($weekday, $weekdays);
        }

        if ($type === 'date_range') {
            $dateCarbon = Carbon::parse($date)->startOfDay();
            $startDate = Carbon::parse($rule->start_date)->startOfDay();
            $endDate = Carbon::parse($rule->end_date)->startOfDay();

            return $dateCarbon->gte($startDate) && $dateCarbon->lte($endDate);
        }

        // 新格式：统一规则（日期范围 + 周几可选）
        $inDateRange = true;
        if ($rule->start_date && $rule->end_date) {
            $dateCarbon = Carbon::parse($date)->startOfDay();
            $startDate = Carbon::parse($rule->start_date)->startOfDay();
            $endDate = Carbon::parse($rule->end_date)->startOfDay();
            $inDateRange = $dateCarbon->gte($startDate) && $dateCarbon->lte($endDate);
        }

        $matchWeekday = true;
        if ($rule->weekdays) {
            $weekday = date('N', strtotime($date));
            $weekdays = explode(',', $rule->weekdays);
            $matchWeekday = in_array($weekday, $weekdays);
        }

        return $inDateRange && $matchWeekday;
    }
}
