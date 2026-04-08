<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform;
use App\Http\Client\MeituanClient;
use App\Services\OTA\OtaInventoryHelper;
use App\Services\ProductService;
use App\Services\ProductUnavailableNightService;
use Illuminate\Support\Facades\Log;

class MeituanService
{
    /**
     * 美团限制：一次推送价格库存的变化不超过40个SKU
     */
    private const MAX_SKU_PER_REQUEST = 40;

    /**
     * 扩大日期范围的天数（用于在“本批全为0”时拉取非零库存日期，满足美团“单次推送不能全为0”的约束）
     */
    private const EXPAND_DAYS_FOR_NONZERO = 60;

    /** @var array<int|string, MeituanClient> 按景区或 'default' 缓存的 client */
    protected array $clientByScenicSpot = [];

    public function __construct(
        protected ProductService $productService,
        protected OtaConfigResolver $otaConfigResolver
    ) {}

    /**
     * 获取 MeituanClient（按景区区分 partnerId，无 scenicSpotId 时使用平台默认）
     */
    protected function getClient(?int $scenicSpotId = null): MeituanClient
    {
        $cacheKey = $scenicSpotId ?? 'default';
        if (!isset($this->clientByScenicSpot[$cacheKey])) {
            $config = $this->otaConfigResolver->getMeituanConfigForScenicSpot($scenicSpotId);
            if (!$config) {
                throw new \Exception('美团配置不存在，请检查 .env 文件中的环境变量配置');
            }
            $this->clientByScenicSpot[$cacheKey] = new MeituanClient($config);
        }
        return $this->clientByScenicSpot[$cacheKey];
    }

    /**
     * 生成partnerPrimaryKey（SKU唯一标识）
     * MD5(hotel_id|room_type_id|date)
     * 
     * @param int $hotelId 酒店ID
     * @param int $roomTypeId 房型ID
     * @param string $date 日期（Y-m-d格式）
     * @return string
     */
    public function generatePartnerPrimaryKey(int $hotelId, int $roomTypeId, string $date): string
    {
        $key = "{$hotelId}|{$roomTypeId}|{$date}";
        return md5($key);
    }

    /**
     * 构建多层价格日历数据（两层：酒店和房型）
     * 
     * @param \App\Models\Product $product 产品
     * @param \App\Models\Hotel $hotel 酒店
     * @param \App\Models\RoomType $roomType 房型
     * @param string $startDate 开始日期（Y-m-d格式）
     * @param string $endDate 结束日期（Y-m-d格式）
     * @return array
     */
    public function buildLevelPriceStockData(
        \App\Models\Product $product,
        \App\Models\Hotel $hotel,
        \App\Models\RoomType $roomType,
        string $startDate,
        string $endDate
    ): array {
        $forceZeroStock = !$hotel->is_active || !$roomType->is_active;

        // 生成日期范围
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        $dates = [];
        while ($start->lte($end)) {
            $dates[] = $start->format('Y-m-d');
            $start->addDay();
        }

        // 先收集所有日期的库存数据
        $inventoryByDate = [];
        foreach ($dates as $date) {
            // 获取库存
            $inventory = \App\Models\Inventory::where('room_type_id', $roomType->id)
                ->where('date', $date)
                ->first();

            // 检查销售日期范围
            $stock = 0;
            $isClosed = true;
            if ($inventory) {
                $isInSalePeriod = true;
                if ($product->sale_start_date || $product->sale_end_date) {
                    $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                    $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                    
                    if ($saleStartDate && $date < $saleStartDate) {
                        $isInSalePeriod = false;
                    }
                    if ($saleEndDate && $date > $saleEndDate) {
                        $isInSalePeriod = false;
                    }
                }
                
                if ($isInSalePeriod && !$inventory->is_closed) {
                    $stock = $inventory->available_quantity;
                    $isClosed = false;
                }
            }

            $inventoryByDate[$date] = [
                'quantity' => $stock,
                'is_closed' => $isClosed,
            ];
        }

        foreach ($dates as $date) {
            if (ProductUnavailableNightService::isNightUnavailable($product, $date)) {
                $inventoryByDate[$date] = [
                    'quantity' => 0,
                    'is_closed' => true,
                ];
            }
        }

        // 酒店或房型被禁用时，产品有效期内库存统一按0推送
        if ($forceZeroStock) {
            foreach ($dates as $date) {
                $inventoryByDate[$date] = [
                    'quantity' => 0,
                    'is_closed' => true,
                ];
            }
        }

        // 如果产品设置了入住天数（stay_days > 1），需要检查连续入住天数的库存
        $stayDays = $product->stay_days;
        if ($stayDays && $stayDays > 1) {
            // 对于每个日期，检查从该日期开始的连续 N 天（N = stay_days）的库存
            $adjustedInventoryByDate = [];
            foreach ($inventoryByDate as $date => $data) {
                $dateObj = \Carbon\Carbon::parse($date);
                $canAccommodate = true;
                
                // 检查从该日期开始的连续 N 天
                for ($i = 0; $i < $stayDays; $i++) {
                    $checkDate = $dateObj->copy()->addDays($i)->format('Y-m-d');
                    
                    // 检查该日期是否有库存记录
                    if (!isset($inventoryByDate[$checkDate])) {
                        // 该日期没有库存记录，无法满足连续入住
                        $canAccommodate = false;
                        break;
                    }
                    
                    // 检查该日期是否关闭或库存为0
                    $checkData = $inventoryByDate[$checkDate];
                    if ($checkData['is_closed'] || $checkData['quantity'] <= 0) {
                        $canAccommodate = false;
                        break;
                    }
                }
                
                // 如果无法满足连续入住，该日期的库存设为0
                if (!$canAccommodate) {
                    $adjustedInventoryByDate[$date] = 0;
                } else {
                    // 可以满足连续入住，使用该日期的实际库存
                    $adjustedInventoryByDate[$date] = $data['quantity'];
                }
            }
            
            // 替换原来的库存数据
            $inventoryByDate = $adjustedInventoryByDate;
        } else {
            // 入住天数为空或1，按原逻辑处理（只考虑关闭状态）
            $adjustedInventoryByDate = [];
            foreach ($inventoryByDate as $date => $data) {
                // 如果该日期关闭，库存为0；否则使用实际库存
                $adjustedInventoryByDate[$date] = $data['is_closed'] ? 0 : $data['quantity'];
            }
            $inventoryByDate = $adjustedInventoryByDate;
        }

        // 构建body数据
        $body = [];
        foreach ($dates as $date) {
            $blocked = ProductUnavailableNightService::checkInTouchesUnavailable($product, $date);
            $priceData = $blocked
                ? ['market_price' => 0.0, 'sale_price' => 0.0, 'settlement_price' => 0.0]
                : $this->productService->calculatePrice($product, $roomType->id, $date);
            
            // 获取调整后的库存
            $stock = $blocked ? 0 : (isset($inventoryByDate[$date]) ? $inventoryByDate[$date] : 0);

            // 生成partnerPrimaryKey
            $partnerPrimaryKey = $this->generatePartnerPrimaryKey($hotel->id, $roomType->id, $date);

            $body[] = [
                'partnerPrimaryKey' => $partnerPrimaryKey,
                'skuInfo' => [
                    'startTime' => '14:00',
                    'endTime' => '16:00',
                    'levelInfoList' => [
                        [
                            'levelNo' => 1,
                            'levelName' => $hotel->name,
                        ],
                        [
                            'levelNo' => 2,
                            'levelName' => $roomType->name,
                        ],
                    ],
                ],
                'priceDate' => $date,
                // 价格单位：元（美团接口要求单位：元，保留两位小数）
                'marketPrice' => round(floatval($priceData['market_price'] ?? $priceData['sale_price']), 2),
                'mtPrice' => round(floatval($priceData['sale_price']), 2),
                'settlementPrice' => round(floatval($priceData['settlement_price']), 2),
                'stock' => OtaInventoryHelper::adjustQuantityForOta((int) $stock), // 真实库存≤2时推0
                'attr' => null,
            ];
        }

        return $body;
    }

    /**
     * 构建多层价格日历数据（支持日期数组，用于增量推送）
     * 
     * @param \App\Models\Product $product 产品
     * @param \App\Models\Hotel $hotel 酒店
     * @param \App\Models\RoomType $roomType 房型
     * @param array $dates 日期数组，格式：['2025-12-27', '2025-12-28']
     * @return array
     */
    public function buildLevelPriceStockDataByDates(
        \App\Models\Product $product,
        \App\Models\Hotel $hotel,
        \App\Models\RoomType $roomType,
        array $dates
    ): array {
        $forceZeroStock = !$hotel->is_active || !$roomType->is_active;

        // 去重并排序日期
        $dates = array_unique($dates);
        sort($dates);

        // 先收集所有日期的库存数据
        $inventoryByDate = [];
        foreach ($dates as $date) {
            // 获取库存
            $inventory = \App\Models\Inventory::where('room_type_id', $roomType->id)
                ->where('date', $date)
                ->first();

            // 检查销售日期范围
            $stock = 0;
            $isClosed = true;
            if ($inventory) {
                $isInSalePeriod = true;
                if ($product->sale_start_date || $product->sale_end_date) {
                    $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                    $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                    
                    if ($saleStartDate && $date < $saleStartDate) {
                        $isInSalePeriod = false;
                    }
                    if ($saleEndDate && $date > $saleEndDate) {
                        $isInSalePeriod = false;
                    }
                }
                
                if ($isInSalePeriod && !$inventory->is_closed) {
                    $stock = $inventory->available_quantity;
                    $isClosed = false;
                }
            }

            $inventoryByDate[$date] = [
                'quantity' => $stock,
                'is_closed' => $isClosed,
            ];
        }

        foreach ($dates as $date) {
            if (ProductUnavailableNightService::isNightUnavailable($product, $date)) {
                $inventoryByDate[$date] = [
                    'quantity' => 0,
                    'is_closed' => true,
                ];
            }
        }

        // 酒店或房型被禁用时，产品有效期内库存统一按0推送
        if ($forceZeroStock) {
            foreach ($dates as $date) {
                $inventoryByDate[$date] = [
                    'quantity' => 0,
                    'is_closed' => true,
                ];
            }
        }

        // 如果产品设置了入住天数（stay_days > 1），需要检查连续入住天数的库存
        $stayDays = $product->stay_days;
        if ($stayDays && $stayDays > 1) {
            // 对于每个日期，检查从该日期开始的连续 N 天（N = stay_days）的库存
            $adjustedInventoryByDate = [];
            foreach ($inventoryByDate as $date => $data) {
                $dateObj = \Carbon\Carbon::parse($date);
                $canAccommodate = true;
                
                // 检查从该日期开始的连续 N 天
                for ($i = 0; $i < $stayDays; $i++) {
                    $checkDate = $dateObj->copy()->addDays($i)->format('Y-m-d');
                    
                    // 检查该日期是否有库存记录
                    if (!isset($inventoryByDate[$checkDate])) {
                        // 该日期没有库存记录，无法满足连续入住
                        $canAccommodate = false;
                        break;
                    }
                    
                    // 检查该日期是否关闭或库存为0
                    $checkData = $inventoryByDate[$checkDate];
                    if ($checkData['is_closed'] || $checkData['quantity'] <= 0) {
                        $canAccommodate = false;
                        break;
                    }
                }
                
                // 如果无法满足连续入住，该日期的库存设为0
                if (!$canAccommodate) {
                    $adjustedInventoryByDate[$date] = 0;
                } else {
                    // 可以满足连续入住，使用该日期的实际库存
                    $adjustedInventoryByDate[$date] = $data['quantity'];
                }
            }
            
            // 替换原来的库存数据
            $inventoryByDate = $adjustedInventoryByDate;
        } else {
            // 入住天数为空或1，按原逻辑处理（只考虑关闭状态）
            $adjustedInventoryByDate = [];
            foreach ($inventoryByDate as $date => $data) {
                // 如果该日期关闭，库存为0；否则使用实际库存
                $adjustedInventoryByDate[$date] = $data['is_closed'] ? 0 : $data['quantity'];
            }
            $inventoryByDate = $adjustedInventoryByDate;
        }

        // 构建body数据
        $body = [];
        foreach ($dates as $date) {
            $blocked = ProductUnavailableNightService::checkInTouchesUnavailable($product, $date);
            $priceData = $blocked
                ? ['market_price' => 0.0, 'sale_price' => 0.0, 'settlement_price' => 0.0]
                : $this->productService->calculatePrice($product, $roomType->id, $date);
            
            // 获取调整后的库存
            $stock = $blocked ? 0 : (isset($inventoryByDate[$date]) ? $inventoryByDate[$date] : 0);

            // 生成partnerPrimaryKey
            $partnerPrimaryKey = $this->generatePartnerPrimaryKey($hotel->id, $roomType->id, $date);

            $body[] = [
                'partnerPrimaryKey' => $partnerPrimaryKey,
                'skuInfo' => [
                    'startTime' => '14:00',
                    'endTime' => '16:00',
                    'levelInfoList' => [
                        [
                            'levelNo' => 1,
                            'levelName' => $hotel->name,
                        ],
                        [
                            'levelNo' => 2,
                            'levelName' => $roomType->name,
                        ],
                    ],
                ],
                'priceDate' => $date,
                // 价格单位：元（美团接口要求单位：元，保留两位小数）
                'marketPrice' => round(floatval($priceData['market_price'] ?? $priceData['sale_price']), 2),
                'mtPrice' => round(floatval($priceData['sale_price']), 2),
                'settlementPrice' => round(floatval($priceData['settlement_price']), 2),
                'stock' => OtaInventoryHelper::adjustQuantityForOta((int) $stock), // 真实库存≤2时推0
                'attr' => null,
            ];
        }

        return $body;
    }

    /**
     * 构建打包产品的多层价格日历数据（支持日期数组）
     * 
     * @param \App\Models\Pkg\PkgProduct $product 打包产品
     * @param \App\Models\Res\ResHotel $hotel 资源层酒店
     * @param \App\Models\Res\ResRoomType $roomType 资源层房型
     * @param array $dates 日期数组，格式：['2025-12-27', '2025-12-28']
     * @return array
     */
    public function buildPkgLevelPriceStockDataByDates(
        \App\Models\Pkg\PkgProduct $product,
        \App\Models\Res\ResHotel $hotel,
        \App\Models\Res\ResRoomType $roomType,
        array $dates
    ): array {
        // 去重并排序日期
        $dates = array_unique($dates);
        sort($dates);

        // 先收集所有日期的库存数据
        $inventoryByDate = [];
        foreach ($dates as $date) {
            // 获取库存
            $dailyStock = \App\Models\Res\ResHotelDailyStock::where('hotel_id', $hotel->id)
                ->where('room_type_id', $roomType->id)
                ->where('biz_date', $date)
                ->first();

            // 检查销售日期范围
            $stock = 0;
            $isClosed = true;
            if ($dailyStock) {
                $isInSalePeriod = true;
                if ($product->sale_start_date || $product->sale_end_date) {
                    $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                    $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                    
                    if ($saleStartDate && $date < $saleStartDate) {
                        $isInSalePeriod = false;
                    }
                    if ($saleEndDate && $date > $saleEndDate) {
                        $isInSalePeriod = false;
                    }
                }
                
                if ($isInSalePeriod && !$dailyStock->is_closed) {
                    $stock = $dailyStock->stock_available;
                    $isClosed = false;
                }
            }

            $inventoryByDate[$date] = [
                'quantity' => $stock,
                'is_closed' => $isClosed,
            ];
        }

        // 如果产品设置了入住天数（stay_days > 1），需要检查连续入住天数的库存
        $stayDays = $product->stay_days;
        if ($stayDays && $stayDays > 1) {
            // 对于每个日期，检查从该日期开始的连续 N 天（N = stay_days）的库存
            $adjustedInventoryByDate = [];
            foreach ($inventoryByDate as $date => $data) {
                $dateObj = \Carbon\Carbon::parse($date);
                $canAccommodate = true;
                
                // 检查从该日期开始的连续 N 天
                for ($i = 0; $i < $stayDays; $i++) {
                    $checkDate = $dateObj->copy()->addDays($i)->format('Y-m-d');
                    
                    // 检查该日期是否有库存记录
                    if (!isset($inventoryByDate[$checkDate])) {
                        // 该日期没有库存记录，无法满足连续入住
                        $canAccommodate = false;
                        break;
                    }
                    
                    // 检查该日期是否关闭或库存为0
                    $checkData = $inventoryByDate[$checkDate];
                    if ($checkData['is_closed'] || $checkData['quantity'] <= 0) {
                        $canAccommodate = false;
                        break;
                    }
                }
                
                // 如果无法满足连续入住，该日期的库存设为0
                if (!$canAccommodate) {
                    $adjustedInventoryByDate[$date] = 0;
                } else {
                    // 可以满足连续入住，使用该日期的实际库存
                    $adjustedInventoryByDate[$date] = $data['quantity'];
                }
            }
            
            // 替换原来的库存数据
            $inventoryByDate = $adjustedInventoryByDate;
        } else {
            // 入住天数为空或1，按原逻辑处理（只考虑关闭状态）
            $adjustedInventoryByDate = [];
            foreach ($inventoryByDate as $date => $data) {
                // 如果该日期关闭，库存为0；否则使用实际库存
                $adjustedInventoryByDate[$date] = $data['is_closed'] ? 0 : $data['quantity'];
            }
            $inventoryByDate = $adjustedInventoryByDate;
        }

        // 构建body数据
        $body = [];
        foreach ($dates as $date) {
            // 获取打包产品的每日价格（价格单位：分）
            $dailyPrice = \App\Models\Pkg\PkgProductDailyPrice::where('pkg_product_id', $product->id)
                ->where('hotel_id', $hotel->id)
                ->where('room_type_id', $roomType->id)
                ->where('biz_date', $date)
                ->first();

            // 如果没有价格数据，跳过（不会出现在body中）
            if (!$dailyPrice) {
                continue;
            }

            // 获取调整后的库存
            $stock = isset($inventoryByDate[$date]) ? $inventoryByDate[$date] : 0;

            // 生成partnerPrimaryKey
            $partnerPrimaryKey = $this->generatePartnerPrimaryKey($hotel->id, $roomType->id, $date);

            // 价格单位：元（美团接口要求单位：元，保留两位小数）
            $salePriceInYuan = round(floatval($dailyPrice->sale_price), 2);
            $marketPriceInYuan = $salePriceInYuan; // 打包产品通常市场价等于售价
            $settlementPriceInYuan = $dailyPrice->cost_price 
                ? round(floatval($dailyPrice->cost_price), 2) 
                : $salePriceInYuan; // 如果没有成本价，使用售价

            $body[] = [
                'partnerPrimaryKey' => $partnerPrimaryKey,
                'skuInfo' => [
                    'startTime' => '14:00',
                    'endTime' => '16:00',
                    'levelInfoList' => [
                        [
                            'levelNo' => 1,
                            'levelName' => $hotel->name,
                        ],
                        [
                            'levelNo' => 2,
                            'levelName' => $roomType->name,
                        ],
                    ],
                ],
                'priceDate' => $date,
                'marketPrice' => $marketPriceInYuan,
                'mtPrice' => $salePriceInYuan,
                'settlementPrice' => $settlementPriceInYuan,
                'stock' => OtaInventoryHelper::adjustQuantityForOta((int) $stock), // 真实库存≤2时推0
                'attr' => null,
            ];
        }

        return $body;
    }

    /**
     * 生成日期范围数组
     * 
     * @param string $startDate 开始日期（Y-m-d格式）
     * @param string $endDate 结束日期（Y-m-d格式）
     * @return array 日期数组，格式：['2025-12-27', '2025-12-28', ...]
     */
    protected function generateDateRange(string $startDate, string $endDate): array
    {
        $dates = [];
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        
        while ($start->lte($end)) {
            $dates[] = $start->format('Y-m-d');
            $start->addDay();
        }
        
        return $dates;
    }

    /**
     * 只保留今天及以后的日期（推送与自动拉取均只使用今天起的日期）
     *
     * @param array $dates 日期数组，格式：['2025-01-01', ...]
     * @return array 过滤后的日期数组
     */
    protected function filterDatesFromToday(array $dates): array
    {
        if (empty($dates)) {
            return [];
        }
        $today = \Carbon\Carbon::today()->format('Y-m-d');
        $filtered = array_values(array_filter($dates, fn (string $d) => $d >= $today));
        sort($filtered);
        return $filtered;
    }

    /**
     * 只保留不晚于产品销售结束日期的日期（若产品有 sale_end_date）
     *
     * @param array $dates 日期数组，格式：['2025-01-01', ...]
     * @param \App\Models\Product|\App\Models\Pkg\PkgProduct $product 产品
     * @return array 过滤后的日期数组
     */
    protected function filterDatesNotAfterSaleEnd(array $dates, $product): array
    {
        if (empty($dates)) {
            return [];
        }
        $saleEnd = $product->sale_end_date ?? null;
        if (!$saleEnd) {
            return $dates;
        }
        $saleEndStr = $saleEnd instanceof \Carbon\Carbon ? $saleEnd->format('Y-m-d') : (string) $saleEnd;
        $filtered = array_values(array_filter($dates, fn (string $d) => $d <= $saleEndStr));
        sort($filtered);
        return $filtered;
    }

    /**
     * 判断一批 body 是否全部为库存 0（美团不接受单次推送全为 0）
     */
    protected function batchIsAllZeroStock(array $batch): bool
    {
        foreach ($batch as $item) {
            if (($item['stock'] ?? 0) > 0) {
                return false;
            }
        }
        return !empty($batch);
    }

    /**
     * 组批时避免“整批全为 0”：每批尽量包含至少一条库存 >0，满足美团限制且能推送 0 库存日期。
     * 返回批次数组，每批最多 MAX_SKU_PER_REQUEST 条；若存在全零批次则排在最后。
     *
     * @param array $bodyItems 已构建的 body 项列表
     * @return array 批次列表，每批为 body 项数组
     */
    protected function formBatchesAvoidingAllZero(array $bodyItems): array
    {
        $nonzero = [];
        $zero = [];
        foreach ($bodyItems as $item) {
            if (($item['stock'] ?? 0) > 0) {
                $nonzero[] = $item;
            } else {
                $zero[] = $item;
            }
        }

        $batches = [];
        while (!empty($nonzero) || !empty($zero)) {
            $batch = [];
            if (!empty($nonzero)) {
                $batch[] = array_shift($nonzero);
            }
            while (count($batch) < self::MAX_SKU_PER_REQUEST && (!empty($zero) || !empty($nonzero))) {
                if (!empty($zero)) {
                    $batch[] = array_shift($zero);
                } else {
                    $batch[] = array_shift($nonzero);
                }
            }
            $batches[] = $batch;
        }
        return $batches;
    }

    /**
     * 同步多层价格日历变化通知V2（增量推送，支持日期数组）
     * 支持常规产品和打包产品，支持分批推送（每批最多40个SKU）
     * 
     * @param \App\Models\Product|\App\Models\Pkg\PkgProduct $product 产品（常规或打包）
     * @param \App\Models\Hotel|\App\Models\Res\ResHotel $hotel 酒店（常规或资源层）
     * @param \App\Models\RoomType|\App\Models\Res\ResRoomType $roomType 房型（常规或资源层）
     * @param array $dates 日期数组，格式：['2025-12-27', '2025-12-28']
     * @return array
     */
    public function syncLevelPriceStockByDates(
        $product,
        $hotel,
        $roomType,
        array $dates
    ): array {
        try {
            if (empty($dates)) {
                return [
                    'success' => false,
                    'message' => '日期数组为空',
                ];
            }

            $scenicSpotId = $product->scenic_spot_id ?? null;
            $client = $this->getClient($scenicSpotId);
            $partnerId = $client->getPartnerId();

            // 去重、排序，并只保留今天及以后的日期
            $dates = array_unique($dates);
            sort($dates);
            $dates = $this->filterDatesFromToday($dates);
            $dates = $this->filterDatesNotAfterSaleEnd($dates, $product);
            if (empty($dates)) {
                return [
                    'success' => false,
                    'message' => '没有今天及以后的日期，跳过推送',
                ];
            }

            // 计算最小和最大日期作为 startTime 和 endTime（用于日志）
            $startDate = min($dates);
            $endDate = max($dates);

            // 判断产品类型
            $isPkgProduct = $product instanceof \App\Models\Pkg\PkgProduct;

            // 构建所有SKU数据（只包含指定的日期）
            if ($isPkgProduct) {
                // 打包产品：使用 buildPkgLevelPriceStockDataByDates
                $allBodyItems = $this->buildPkgLevelPriceStockDataByDates(
                    $product,
                    $hotel,
                    $roomType,
                    $dates
                );
                $productCode = $product->product_code;
            } else {
                // 常规产品：使用现有方法
                $allBodyItems = $this->buildLevelPriceStockDataByDates(
                    $product,
                    $hotel,
                    $roomType,
                    $dates
                );
                $productCode = $product->code;
            }

            if (empty($allBodyItems)) {
                return [
                    'success' => false,
                    'message' => '没有价格库存数据',
                ];
            }

            // 组批：保证每批至少一条库存>0（美团不接受单次推送全为0），同时把需推送的 0 库存日期都带上
            $batches = $this->formBatchesAvoidingAllZero($allBodyItems);
            $allZeroBatchIndices = [];
            foreach ($batches as $idx => $batch) {
                if ($this->batchIsAllZeroStock($batch)) {
                    $allZeroBatchIndices[] = $idx;
                }
            }
            if (!empty($allZeroBatchIndices)) {
                $expandStart = \Carbon\Carbon::parse($startDate)->subDays(self::EXPAND_DAYS_FOR_NONZERO)->format('Y-m-d');
                $expandEnd = \Carbon\Carbon::parse($endDate)->addDays(self::EXPAND_DAYS_FOR_NONZERO)->format('Y-m-d');
                $expandedDates = $this->generateDateRange($expandStart, $expandEnd);
                $extraDates = array_values(array_diff($expandedDates, $dates));
                $extraDates = $this->filterDatesFromToday($extraDates);
                $extraDates = $this->filterDatesNotAfterSaleEnd($extraDates, $product);
                if (!empty($extraDates)) {
                    $extraBody = $isPkgProduct
                        ? $this->buildPkgLevelPriceStockDataByDates($product, $hotel, $roomType, $extraDates)
                        : $this->buildLevelPriceStockDataByDates($product, $hotel, $roomType, $extraDates);
                    $nonZeroExtra = array_values(array_filter($extraBody, fn ($item) => ($item['stock'] ?? 0) > 0));
                    $needCount = count($allZeroBatchIndices);
                    $toAdd = array_slice($nonZeroExtra, 0, $needCount);
                    if (!empty($toAdd)) {
                        $allBodyItems = array_merge($allBodyItems, $toAdd);
                        $batches = $this->formBatchesAvoidingAllZero($allBodyItems);
                    }
                }
            }

            $totalBatches = count($batches);
            $allResults = [];

            Log::info('美团价格推送（增量）：开始分批推送', [
                'product_type' => $isPkgProduct ? 'pkg' : 'regular',
                'product_code' => $productCode,
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'total_sku' => count($allBodyItems),
                'total_batches' => $totalBatches,
                'dates' => $dates,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            foreach ($batches as $batchIndex => $batchBody) {
                if ($this->batchIsAllZeroStock($batchBody)) {
                    Log::warning('美团价格推送（增量）：本批库存全为0，美团不支持单次推送全为0，跳过本批次（可能造成超订，请关注）', [
                        'batch_index' => $batchIndex + 1,
                        'total_batches' => $totalBatches,
                        'batch_dates' => array_column($batchBody, 'priceDate'),
                        'product_id' => $product->id,
                        'room_type_id' => $roomType->id,
                    ]);
                    continue;
                }

                // 计算当前批次的日期范围
                $batchDates = array_column($batchBody, 'priceDate');
                $batchStartDate = min($batchDates);
                $batchEndDate = max($batchDates);

                // 构建请求数据
                $requestData = [
                    'partnerId' => $partnerId,
                    'startTime' => $batchStartDate,
                    'endTime' => $batchEndDate,
                    'partnerDealId' => $productCode,
                    'body' => $batchBody, // body数组，会在MeituanClient中加密
                ];

                Log::info('美团价格推送（增量）：批次开始', [
                    'batch_index' => $batchIndex + 1,
                    'total_batches' => $totalBatches,
                    'batch_size' => count($batchBody),
                    'start_date' => $batchStartDate,
                    'end_date' => $batchEndDate,
                ]);

                // 记录本批推送数据（便于排查库存/价格推送问题）
                Log::info('美团价格推送（增量）：本批推送数据', [
                    'batch_index' => $batchIndex + 1,
                    'total_batches' => $totalBatches,
                    'partnerDealId' => $productCode,
                    'startTime' => $batchStartDate,
                    'endTime' => $batchEndDate,
                    'items' => array_map(fn ($item) => [
                        'priceDate' => $item['priceDate'],
                        'marketPrice' => $item['marketPrice'] ?? null,
                        'mtPrice' => $item['mtPrice'],
                        'settlementPrice' => $item['settlementPrice'] ?? null,
                        'stock' => $item['stock'],
                    ], $batchBody),
                ]);

                $result = $client->notifyLevelPriceStock($requestData);

                // 记录批次结果
                $allResults[] = $result;

                // 检查响应
                if (isset($result['code']) && $result['code'] == 200) {
                    Log::info('美团价格推送（增量）：批次成功', [
                        'batch_index' => $batchIndex + 1,
                        'total_batches' => $totalBatches,
                        'batch_size' => count($batchBody),
                    ]);
                } else {
                    Log::error('美团价格推送（增量）：批次失败', [
                        'batch_index' => $batchIndex + 1,
                        'total_batches' => $totalBatches,
                        'batch_size' => count($batchBody),
                        'result' => $result,
                    ]);
                }
            }

            if (!empty($batches) && empty($allResults)) {
                Log::error('同步美团多层价格日历（增量推送）：所有批次均为全0库存，美团不支持单次推送全为0，未推送任何批次，可能造成超订', [
                    'product_id' => $product->id,
                    'room_type_id' => $roomType->id,
                    'dates' => $dates,
                ]);
                return [
                    'success' => false,
                    'message' => '本次推送的日期库存均为0，美团不支持单次推送全为0，已跳过全部批次，可能造成超订',
                    'data' => [],
                ];
            }

            // 检查所有批次是否都成功
            $allSuccess = true;
            foreach ($allResults as $result) {
                if (!isset($result['code']) || $result['code'] != 200) {
                    $allSuccess = false;
                    break;
                }
            }

            if ($allSuccess) {
                Log::info('同步美团多层价格日历（增量推送）：所有批次成功', [
                    'product_type' => $isPkgProduct ? 'pkg' : 'regular',
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'total_batches' => $totalBatches,
                ]);

                return [
                    'success' => true,
                    'message' => '同步成功',
                    'data' => $allResults,
                ];
            } else {
                Log::error('同步美团多层价格日历（增量推送）：部分批次失败', [
                    'product_type' => $isPkgProduct ? 'pkg' : 'regular',
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'total_batches' => $totalBatches,
                    'results' => $allResults,
                ]);

                return [
                    'success' => false,
                    'message' => '部分批次失败',
                    'data' => $allResults,
                ];
            }
        } catch (\Exception $e) {
            Log::error('同步美团多层价格日历（增量推送）异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'dates' => $dates,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '同步异常：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 同步多层价格日历变化通知V2（主动推送）
     * 支持常规产品和打包产品，支持分批推送（每批最多40个SKU）
     * 
     * @param \App\Models\Product|\App\Models\Pkg\PkgProduct $product 产品（常规或打包）
     * @param \App\Models\Hotel|\App\Models\Res\ResHotel $hotel 酒店（常规或资源层）
     * @param \App\Models\RoomType|\App\Models\Res\ResRoomType $roomType 房型（常规或资源层）
     * @param string $startDate 开始日期（Y-m-d格式）
     * @param string $endDate 结束日期（Y-m-d格式）
     * @return array
     */
    public function syncLevelPriceStock(
        $product,
        $hotel,
        $roomType,
        string $startDate,
        string $endDate
    ): array {
        try {
            $scenicSpotId = $product->scenic_spot_id ?? null;
            $client = $this->getClient($scenicSpotId);
            $partnerId = $client->getPartnerId();

            // 判断产品类型
            $isPkgProduct = $product instanceof \App\Models\Pkg\PkgProduct;

            $today = \Carbon\Carbon::today()->format('Y-m-d');
            if ($endDate < $today) {
                return [
                    'success' => false,
                    'message' => '结束日期早于今天，没有可推送的日期',
                ];
            }
            $startDate = max($startDate, $today);
            $saleEndStr = $product->sale_end_date
                ? ($product->sale_end_date instanceof \Carbon\Carbon ? $product->sale_end_date->format('Y-m-d') : (string) $product->sale_end_date)
                : null;
            if ($saleEndStr !== null && $endDate > $saleEndStr) {
                $endDate = $saleEndStr;
            }
            if ($startDate > $endDate) {
                return [
                    'success' => false,
                    'message' => '没有可推送的日期（今天起不晚于销售结束日期）',
                ];
            }

            // 构建所有SKU数据（仅今天及以后的日期，且不超过销售结束日期）
            if ($isPkgProduct) {
                $dates = $this->generateDateRange($startDate, $endDate);
                $dates = $this->filterDatesFromToday($dates);
                if (empty($dates)) {
                    return [
                        'success' => false,
                        'message' => '没有今天及以后的日期，跳过推送',
                    ];
                }
                $allBodyItems = $this->buildPkgLevelPriceStockDataByDates(
                    $product,
                    $hotel,
                    $roomType,
                    $dates
                );
                $productCode = $product->product_code;
                $requestedDates = $dates;
            } else {
                $allBodyItems = $this->buildLevelPriceStockData(
                    $product,
                    $hotel,
                    $roomType,
                    $startDate,
                    $endDate
                );
                $productCode = $product->code;
                $requestedDates = $this->generateDateRange($startDate, $endDate);
            }

            if (empty($allBodyItems)) {
                return [
                    'success' => false,
                    'message' => '没有价格库存数据',
                ];
            }

            // 组批：保证每批至少一条库存>0（美团不接受单次推送全为0）
            $batches = $this->formBatchesAvoidingAllZero($allBodyItems);
            $allZeroBatchIndices = [];
            foreach ($batches as $idx => $batch) {
                if ($this->batchIsAllZeroStock($batch)) {
                    $allZeroBatchIndices[] = $idx;
                }
            }
            if (!empty($allZeroBatchIndices)) {
                $expandStart = \Carbon\Carbon::parse($startDate)->subDays(self::EXPAND_DAYS_FOR_NONZERO)->format('Y-m-d');
                $expandEnd = \Carbon\Carbon::parse($endDate)->addDays(self::EXPAND_DAYS_FOR_NONZERO)->format('Y-m-d');
                $expandedDates = $this->generateDateRange($expandStart, $expandEnd);
                $extraDates = array_values(array_diff($expandedDates, $requestedDates));
                $extraDates = $this->filterDatesFromToday($extraDates);
                $extraDates = $this->filterDatesNotAfterSaleEnd($extraDates, $product);
                if (!empty($extraDates)) {
                    $extraBody = $isPkgProduct
                        ? $this->buildPkgLevelPriceStockDataByDates($product, $hotel, $roomType, $extraDates)
                        : $this->buildLevelPriceStockDataByDates($product, $hotel, $roomType, $extraDates);
                    $nonZeroExtra = array_values(array_filter($extraBody, fn ($item) => ($item['stock'] ?? 0) > 0));
                    $needCount = count($allZeroBatchIndices);
                    $toAdd = array_slice($nonZeroExtra, 0, $needCount);
                    if (!empty($toAdd)) {
                        $allBodyItems = array_merge($allBodyItems, $toAdd);
                        $batches = $this->formBatchesAvoidingAllZero($allBodyItems);
                    }
                }
            }

            $totalBatches = count($batches);
            $allResults = [];

            Log::info('美团价格推送：开始分批推送', [
                'product_type' => $isPkgProduct ? 'pkg' : 'regular',
                'product_code' => $productCode,
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'total_sku' => count($allBodyItems),
                'total_batches' => $totalBatches,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            foreach ($batches as $batchIndex => $batchBody) {
                if ($this->batchIsAllZeroStock($batchBody)) {
                    Log::warning('美团价格推送：本批库存全为0，美团不支持单次推送全为0，跳过本批次（可能造成超订，请关注）', [
                        'batch_index' => $batchIndex + 1,
                        'total_batches' => $totalBatches,
                        'batch_dates' => array_column($batchBody, 'priceDate'),
                        'product_id' => $product->id,
                        'room_type_id' => $roomType->id,
                    ]);
                    continue;
                }

                // 计算当前批次的日期范围
                $batchDates = array_column($batchBody, 'priceDate');
                $batchStartDate = min($batchDates);
                $batchEndDate = max($batchDates);

                // 构建请求数据
                $requestData = [
                    'partnerId' => $partnerId,
                    'startTime' => $batchStartDate,
                    'endTime' => $batchEndDate,
                    'partnerDealId' => $productCode,
                    'body' => $batchBody, // body数组，会在MeituanClient中加密
                ];

                Log::info('美团价格推送：批次开始', [
                    'batch_index' => $batchIndex + 1,
                    'total_batches' => $totalBatches,
                    'batch_size' => count($batchBody),
                    'start_date' => $batchStartDate,
                    'end_date' => $batchEndDate,
                ]);

                // 记录本批推送数据（便于排查库存/价格推送问题）
                Log::info('美团价格推送：本批推送数据', [
                    'batch_index' => $batchIndex + 1,
                    'total_batches' => $totalBatches,
                    'partnerDealId' => $productCode,
                    'startTime' => $batchStartDate,
                    'endTime' => $batchEndDate,
                    'items' => array_map(fn ($item) => [
                        'priceDate' => $item['priceDate'],
                        'marketPrice' => $item['marketPrice'] ?? null,
                        'mtPrice' => $item['mtPrice'],
                        'settlementPrice' => $item['settlementPrice'] ?? null,
                        'stock' => $item['stock'],
                    ], $batchBody),
                ]);

                $result = $client->notifyLevelPriceStock($requestData);

                // 记录批次结果
                $allResults[] = $result;

                // 检查响应
                if (isset($result['code']) && $result['code'] == 200) {
                    Log::info('美团价格推送：批次成功', [
                        'batch_index' => $batchIndex + 1,
                        'total_batches' => $totalBatches,
                        'batch_size' => count($batchBody),
                    ]);
                } else {
                    Log::error('美团价格推送：批次失败', [
                        'batch_index' => $batchIndex + 1,
                        'total_batches' => $totalBatches,
                        'batch_size' => count($batchBody),
                        'result' => $result,
                    ]);
                }
            }

            if (!empty($batches) && empty($allResults)) {
                Log::error('同步美团多层价格日历：所有批次均为全0库存，美团不支持单次推送全为0，未推送任何批次，可能造成超订', [
                    'product_id' => $product->id,
                    'room_type_id' => $roomType->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]);
                return [
                    'success' => false,
                    'message' => '本次推送的日期库存均为0，美团不支持单次推送全为0，已跳过全部批次，可能造成超订',
                    'data' => [],
                ];
            }

            // 检查所有批次是否都成功
            $allSuccess = true;
            foreach ($allResults as $result) {
                if (!isset($result['code']) || $result['code'] != 200) {
                    $allSuccess = false;
                    break;
                }
            }

            if ($allSuccess) {
                Log::info('同步美团多层价格日历：所有批次成功', [
                    'product_type' => $isPkgProduct ? 'pkg' : 'regular',
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'total_batches' => $totalBatches,
                ]);

                return [
                    'success' => true,
                    'message' => '同步成功',
                    'data' => $allResults,
                ];
            } else {
                Log::error('同步美团多层价格日历：部分批次失败', [
                    'product_type' => $isPkgProduct ? 'pkg' : 'regular',
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'total_batches' => $totalBatches,
                    'results' => $allResults,
                ]);

                return [
                    'success' => false,
                    'message' => '部分批次失败',
                    'data' => $allResults,
                ];
            }
        } catch (\Exception $e) {
            Log::error('同步美团多层价格日历异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '同步异常：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 同步多层价格日历（全量、单次请求，不分批）
     * 用于库存变化场景：达到库存阈值时，按销售日期范围全量推送，且将所有 SKU 合并为一条请求发送（不按 40 条/批切分）。
     *
     * @param \App\Models\Product|\App\Models\Pkg\PkgProduct $product
     * @param \App\Models\Hotel|\App\Models\Res\ResHotel $hotel
     * @param \App\Models\RoomType|\App\Models\Res\ResRoomType $roomType
     * @param string $startDate Y-m-d
     * @param string $endDate Y-m-d
     * @return array{success: bool, message: string, data?: array}
     */
    public function syncLevelPriceStockSingleRequest(
        $product,
        $hotel,
        $roomType,
        string $startDate,
        string $endDate
    ): array {
        try {
            $scenicSpotId = $product->scenic_spot_id ?? null;
            $client = $this->getClient($scenicSpotId);
            $partnerId = $client->getPartnerId();

            $isPkgProduct = $product instanceof \App\Models\Pkg\PkgProduct;

            $today = \Carbon\Carbon::today()->format('Y-m-d');
            if ($endDate < $today) {
                return [
                    'success' => false,
                    'message' => '结束日期早于今天，没有可推送的日期',
                ];
            }
            $startDate = max($startDate, $today);
            $saleEndStr = $product->sale_end_date
                ? ($product->sale_end_date instanceof \Carbon\Carbon ? $product->sale_end_date->format('Y-m-d') : (string) $product->sale_end_date)
                : null;
            if ($saleEndStr !== null && $endDate > $saleEndStr) {
                $endDate = $saleEndStr;
            }
            if ($startDate > $endDate) {
                return [
                    'success' => false,
                    'message' => '没有可推送的日期（今天起不晚于销售结束日期）',
                ];
            }

            if ($isPkgProduct) {
                $dates = $this->generateDateRange($startDate, $endDate);
                $dates = $this->filterDatesFromToday($dates);
                if (empty($dates)) {
                    return [
                        'success' => false,
                        'message' => '没有今天及以后的日期，跳过推送',
                    ];
                }
                $allBodyItems = $this->buildPkgLevelPriceStockDataByDates(
                    $product,
                    $hotel,
                    $roomType,
                    $dates
                );
                $productCode = $product->product_code;
            } else {
                $allBodyItems = $this->buildLevelPriceStockData(
                    $product,
                    $hotel,
                    $roomType,
                    $startDate,
                    $endDate
                );
                $productCode = $product->code;
            }

            if (empty($allBodyItems)) {
                return [
                    'success' => false,
                    'message' => '没有价格库存数据',
                ];
            }

            // 美团不接受单次推送全为 0：若全部为 0 则不发送
            $hasNonZero = false;
            foreach ($allBodyItems as $item) {
                if (($item['stock'] ?? 0) > 0) {
                    $hasNonZero = true;
                    break;
                }
            }
            if (!$hasNonZero) {
                Log::error('同步美团多层价格日历（单次请求）：全部库存为0，美团不支持单次推送全为0，未推送', [
                    'product_id' => $product->id,
                    'room_type_id' => $roomType->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]);
                return [
                    'success' => false,
                    'message' => '本次推送的日期库存均为0，美团不支持单次推送全为0，已跳过，可能造成超订',
                    'data' => [],
                ];
            }

            $requestData = [
                'partnerId' => $partnerId,
                'startTime' => $startDate,
                'endTime' => $endDate,
                'partnerDealId' => $productCode,
                'body' => $allBodyItems,
            ];

            Log::info('美团价格推送（单次请求）：开始全量推送', [
                'product_type' => $isPkgProduct ? 'pkg' : 'regular',
                'product_code' => $productCode,
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'total_sku' => count($allBodyItems),
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            $result = $client->notifyLevelPriceStock($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('美团价格推送（单次请求）：成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'total_sku' => count($allBodyItems),
                ]);
                return [
                    'success' => true,
                    'message' => '同步成功',
                    'data' => [$result],
                ];
            }

            Log::error('美团价格推送（单次请求）：失败', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'total_sku' => count($allBodyItems),
                'result' => $result,
            ]);
            return [
                'success' => false,
                'message' => $result['describe'] ?? $result['message'] ?? '推送失败',
                'data' => [$result],
            ];
        } catch (\Exception $e) {
            Log::error('同步美团多层价格日历（单次请求）异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'success' => false,
                'message' => '同步异常：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 同步多层价格日历（按产品维度：整个产品下所有酒店+房型合并为 1 次请求）
     * 用于产品管理推送、库存变化推送：将产品关联的所有「酒店+房型」的 SKU 合并到一条 body，只发 1 次 API 请求。
     *
     * @param \App\Models\Product $product 常规产品（不支持 PkgProduct）
     * @param string $startDate Y-m-d
     * @param string $endDate Y-m-d
     * @return array{success: bool, message: string, data?: array, combos_count?: int}
     */
    public function syncLevelPriceStockProductWide(
        \App\Models\Product $product,
        string $startDate,
        string $endDate
    ): array {
        try {
            $client = $this->getClient($product->scenic_spot_id);
            $partnerId = $client->getPartnerId();

            if (empty($product->code)) {
                return [
                    'success' => false,
                    'message' => '产品编码为空，请先设置产品编码',
                ];
            }

            $today = \Carbon\Carbon::today()->format('Y-m-d');
            if ($endDate < $today) {
                return [
                    'success' => false,
                    'message' => '结束日期早于今天，没有可推送的日期',
                ];
            }
            $startDate = max($startDate, $today);
            $saleEndStr = $product->sale_end_date
                ? ($product->sale_end_date instanceof \Carbon\Carbon ? $product->sale_end_date->format('Y-m-d') : (string) $product->sale_end_date)
                : null;
            if ($saleEndStr !== null && $endDate > $saleEndStr) {
                $endDate = $saleEndStr;
            }
            if ($startDate > $endDate) {
                return [
                    'success' => false,
                    'message' => '没有可推送的日期（今天起不晚于销售结束日期）',
                ];
            }

            // 获取产品下所有有效的「酒店+房型」组合（与 PushProductToOtaJob 规则一致）
            $prices = $product->prices()->with(['roomType.hotel'])->get();
            $combos = [];
            $seen = [];
            foreach ($prices as $price) {
                $roomType = $price->roomType;
                if (!$roomType) {
                    continue;
                }
                $hotel = $roomType->hotel;
                if (!$hotel || empty($hotel->code) || empty($roomType->code)) {
                    continue;
                }
                $key = $hotel->id . '_' . $roomType->id;
                if (!isset($seen[$key])) {
                    $combos[] = ['hotel' => $hotel, 'room_type' => $roomType];
                    $seen[$key] = true;
                }
            }

            if (empty($combos)) {
                return [
                    'success' => false,
                    'message' => '产品未关联有效的酒店和房型（编码为空）',
                ];
            }

            // 为每个组合构建 body，再合并成一条
            $allBodyItems = [];
            foreach ($combos as $combo) {
                $items = $this->buildLevelPriceStockData(
                    $product,
                    $combo['hotel'],
                    $combo['room_type'],
                    $startDate,
                    $endDate
                );
                foreach ($items as $item) {
                    $allBodyItems[] = $item;
                }
            }

            if (empty($allBodyItems)) {
                return [
                    'success' => false,
                    'message' => '没有价格库存数据',
                ];
            }

            // 美团不接受单次推送全为 0
            $hasNonZero = false;
            foreach ($allBodyItems as $item) {
                if (($item['stock'] ?? 0) > 0) {
                    $hasNonZero = true;
                    break;
                }
            }
            if (!$hasNonZero) {
                Log::error('同步美团多层价格日历（整产品单次请求）：全部库存为0，美团不支持单次推送全为0，未推送', [
                    'product_id' => $product->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'combos_count' => count($combos),
                ]);
                return [
                    'success' => false,
                    'message' => '本次推送的日期库存均为0，美团不支持单次推送全为0，已跳过，可能造成超订',
                    'data' => [],
                ];
            }

            $requestData = [
                'partnerId' => $partnerId,
                'startTime' => $startDate,
                'endTime' => $endDate,
                'partnerDealId' => $product->code,
                'body' => $allBodyItems,
            ];

            Log::info('美团价格推送（整产品单次请求）：开始全量推送', [
                'product_code' => $product->code,
                'product_id' => $product->id,
                'combos_count' => count($combos),
                'total_sku' => count($allBodyItems),
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            $result = $client->notifyLevelPriceStock($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('美团价格推送（整产品单次请求）：成功', [
                    'product_id' => $product->id,
                    'combos_count' => count($combos),
                    'total_sku' => count($allBodyItems),
                ]);
                return [
                    'success' => true,
                    'message' => '同步成功',
                    'data' => [$result],
                    'combos_count' => count($combos),
                ];
            }

            Log::error('美团价格推送（整产品单次请求）：失败', [
                'product_id' => $product->id,
                'combos_count' => count($combos),
                'total_sku' => count($allBodyItems),
                'result' => $result,
            ]);
            return [
                'success' => false,
                'message' => $result['describe'] ?? $result['message'] ?? '推送失败',
                'data' => [$result],
            ];
        } catch (\Exception $e) {
            Log::error('同步美团多层价格日历（整产品单次请求）异常', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'success' => false,
                'message' => '同步异常：' . $e->getMessage(),
            ];
        }
    }
}
