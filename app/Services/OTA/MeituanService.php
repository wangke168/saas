<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform;
use App\Http\Client\MeituanClient;
use App\Models\OtaConfig;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Services\ProductService;
use Illuminate\Support\Facades\Log;

class MeituanService
{
    /**
     * 美团限制：一次推送价格库存的变化不超过40个SKU
     */
    private const MAX_SKU_PER_REQUEST = 40;

    protected ?MeituanClient $client = null;

    public function __construct(
        protected ProductService $productService
    ) {}

    /**
     * 获取MeituanClient实例
     */
    protected function getClient(): MeituanClient
    {
        if ($this->client === null) {
            // 优先使用环境变量配置（如果存在）
            $config = $this->createConfigFromEnv();
            
            // 如果环境变量配置不存在，尝试从数据库读取
            if (!$config) {
                $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
                $config = $platform?->config;
            }

            if (!$config) {
                throw new \Exception('美团配置不存在，请检查数据库配置或环境变量');
            }

            $this->client = new MeituanClient($config);
        }

        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?OtaConfig
    {
        // 检查环境变量是否存在
        if (!env('MEITUAN_PARTNER_ID') || !env('MEITUAN_APP_KEY') || !env('MEITUAN_APP_SECRET')) {
            return null;
        }

        // 创建临时配置对象（不保存到数据库）
        $config = new OtaConfig();
        $config->account = env('MEITUAN_PARTNER_ID'); // PartnerId存储在account字段
        $config->secret_key = env('MEITUAN_APP_KEY'); // AppKey存储在secret_key字段
        $config->aes_key = env('MEITUAN_APP_SECRET'); // AppSecret存储在aes_key字段
        $config->aes_iv = env('MEITUAN_AES_KEY', ''); // AES密钥存储在aes_iv字段
        
        // API URL 配置
        // 根据美团文档，正确的API地址是 https://connectivity-adapter.meituan.com
        $config->api_url = env('MEITUAN_API_URL', 'https://connectivity-adapter.meituan.com');
        $config->callback_url = env('MEITUAN_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
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
        $body = [];

        // 生成日期范围
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        $dates = [];
        while ($start->lte($end)) {
            $dates[] = $start->format('Y-m-d');
            $start->addDay();
        }

        foreach ($dates as $date) {
            // 计算价格
            $priceData = $this->productService->calculatePrice($product, $roomType->id, $date);
            
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
                'stock' => $stock,
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
        $body = [];

        // 去重并排序日期
        $dates = array_unique($dates);
        sort($dates);

        foreach ($dates as $date) {
            // 计算价格
            $priceData = $this->productService->calculatePrice($product, $roomType->id, $date);
            
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
                'stock' => $stock,
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
        $body = [];

        // 去重并排序日期
        $dates = array_unique($dates);
        sort($dates);

        foreach ($dates as $date) {
            // 获取打包产品的每日价格（价格单位：分）
            $dailyPrice = \App\Models\Pkg\PkgProductDailyPrice::where('pkg_product_id', $product->id)
                ->where('hotel_id', $hotel->id)
                ->where('room_type_id', $roomType->id)
                ->where('biz_date', $date)
                ->first();

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

            // 如果没有价格数据，跳过（不会出现在body中）
            if (!$dailyPrice) {
                continue;
            }

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
                'stock' => $stock,
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

            $client = $this->getClient();
            $partnerId = $client->getPartnerId();

            // 去重并排序日期
            $dates = array_unique($dates);
            sort($dates);

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

            // 分批处理（每批最多40个SKU）
            $batches = array_chunk($allBodyItems, self::MAX_SKU_PER_REQUEST);
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
            $client = $this->getClient();
            $partnerId = $client->getPartnerId();

            // 判断产品类型
            $isPkgProduct = $product instanceof \App\Models\Pkg\PkgProduct;

            // 构建所有SKU数据
            if ($isPkgProduct) {
                // 打包产品：构建日期范围，然后调用 buildPkgLevelPriceStockDataByDates
                $dates = $this->generateDateRange($startDate, $endDate);
                $allBodyItems = $this->buildPkgLevelPriceStockDataByDates(
                    $product,
                    $hotel,
                    $roomType,
                    $dates
                );
                $productCode = $product->product_code;
            } else {
                // 常规产品：使用现有方法
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

            // 分批处理（每批最多40个SKU）
            $batches = array_chunk($allBodyItems, self::MAX_SKU_PER_REQUEST);
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
}
