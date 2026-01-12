<?php

namespace App\Services\Pkg;

use App\Models\Pkg\PkgProduct;
use App\Models\Pkg\PkgProductDailyPrice;
use App\Models\Res\ResHotel;
use App\Models\Res\ResRoomType;
use App\Models\Res\ResHotelDailyStock;
use App\Services\OTA\CtripService;
use App\Http\Client\CtripClient;
use App\Models\OtaConfig;
use App\Helpers\CtripErrorCodeHelper;
use App\Enums\OtaPlatform;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * 打包产品OTA价格推送服务
 * 
 * 功能：
 * 1. 将打包产品的预计算价格推送到OTA平台（携程、美团等）
 * 2. 按"产品-酒店-房型"组合推送价格日历
 * 3. 使用复合编码（PKG|RoomID|HotelID|ProductID）作为OTA产品标识
 */
class PkgProductOtaSyncService
{
    protected CtripService $ctripService;
    protected ?CtripClient $ctripClient = null;

    public function __construct(CtripService $ctripService)
    {
        $this->ctripService = $ctripService;
    }

    /**
     * 获取携程客户端实例
     * 直接创建 CtripClient，避免通过 CtripService 的 protected 方法
     */
    protected function getCtripClient(): CtripClient
    {
        if ($this->ctripClient === null) {
            // 创建配置对象（从环境变量读取，与 CtripService 保持一致）
            $config = new OtaConfig();
            $config->account = env('CTRIP_ACCOUNT_ID');
            $config->secret_key = env('CTRIP_SECRET_KEY');
            $config->aes_key = env('CTRIP_ENCRYPT_KEY', '');
            $config->aes_iv = env('CTRIP_ENCRYPT_IV', '');
            // API URL 配置（从环境变量读取，CtripClient 会根据接口类型使用对应的 URL）
            $config->api_url = env('CTRIP_PRICE_API_URL', 'https://ttdopen.ctrip.com/api/product/price.do');
            $config->callback_url = env('CTRIP_WEBHOOK_URL', '');
            $config->environment = 'production';
            $config->is_active = true;

            if (!$config->account || !$config->secret_key) {
                throw new \Exception('携程配置不存在，请检查 .env 文件中的环境变量配置');
            }

            $this->ctripClient = new CtripClient($config);
        }

        return $this->ctripClient;
    }

    /**
     * 推送产品的价格到OTA平台
     * 
     * @param PkgProduct $product 打包产品
     * @param string $otaPlatformCode OTA平台编码（ctrip, meituan等）
     * @param array|null $dates 指定日期数组，如果为null则推送未来60天
     * @return array ['success' => bool, 'message' => string, 'details' => array]
     */
    public function syncProductPricesToOta(
        PkgProduct $product,
        string $otaPlatformCode,
        ?array $dates = null
    ): array {
        try {
            // 验证产品编码
            if (empty($product->product_code)) {
                return [
                    'success' => false,
                    'message' => '产品编码为空，无法推送到OTA',
                ];
            }

            // 验证产品状态
            if ($product->status !== 1) {
                return [
                    'success' => false,
                    'message' => '产品未启用，无法推送到OTA',
                ];
            }

            // 获取产品的所有关联房型（只推送启用状态的房型）
            $hotelRoomTypes = $product->hotelRoomTypes()
                ->with(['roomType', 'hotel'])
                ->whereHas('roomType', function ($query) {
                    $query->where('is_active', true);
                })
                ->whereHas('hotel', function ($query) {
                    $query->where('is_active', true);
                })
                ->get();

            if ($hotelRoomTypes->isEmpty()) {
                return [
                    'success' => false,
                    'message' => '产品没有关联的启用房型',
                ];
            }

            $results = [];
            $successCount = 0;
            $failCount = 0;

            // 为每个房型组合推送价格
            foreach ($hotelRoomTypes as $hotelRoomType) {
                $hotel = $hotelRoomType->hotel;
                $roomType = $hotelRoomType->roomType;

                // 验证编码
                if (empty($hotel->code) || empty($roomType->code)) {
                    $results[] = [
                        'hotel' => $hotel->name,
                        'room_type' => $roomType->name,
                        'success' => false,
                        'message' => '酒店或房型编码为空',
                    ];
                    $failCount++;
                    continue;
                }

                // 推送单个房型的价格日历
                $result = $this->syncRoomTypePriceCalendar(
                    $product,
                    $hotel,
                    $roomType,
                    $otaPlatformCode,
                    $dates
                );

                $results[] = [
                    'hotel' => $hotel->name,
                    'room_type' => $roomType->name,
                    'composite_code' => $this->buildOtaCode($roomType, $hotel, $product),
                    'success' => $result['success'],
                    'message' => $result['message'],
                ];

                if ($result['success']) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }

            return [
                'success' => $failCount === 0,
                'message' => "推送完成：成功 {$successCount} 个，失败 {$failCount} 个",
                'details' => $results,
                'summary' => [
                    'total' => count($results),
                    'success' => $successCount,
                    'failed' => $failCount,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('PkgProductOtaSyncService: 推送价格失败', [
                'product_id' => $product->id,
                'platform' => $otaPlatformCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '推送失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 推送单个房型的价格日历到OTA
     * 
     * @param PkgProduct $product 打包产品
     * @param ResHotel $hotel 酒店
     * @param ResRoomType $roomType 房型
     * @param string $otaPlatformCode OTA平台编码
     * @param array|null $dates 指定日期数组
     * @return array
     */
    protected function syncRoomTypePriceCalendar(
        PkgProduct $product,
        ResHotel $hotel,
        ResRoomType $roomType,
        string $otaPlatformCode,
        ?array $dates = null
    ): array {
        try {
            // 根据平台选择不同的推送逻辑
            switch ($otaPlatformCode) {
                case OtaPlatform::CTRIP->value:
                    return $this->syncToCtrip($product, $hotel, $roomType, $dates);
                case OtaPlatform::MEITUAN->value:
                    // TODO: 实现美团推送逻辑
                    return [
                        'success' => false,
                        'message' => '美团平台推送功能待实现',
                    ];
                default:
                    return [
                        'success' => false,
                        'message' => "不支持的OTA平台：{$otaPlatformCode}",
                    ];
            }
        } catch (\Exception $e) {
            Log::error('PkgProductOtaSyncService: 推送房型价格失败', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'platform' => $otaPlatformCode,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '推送失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 推送到携程
     * 
     * 注意：打包产品使用复合编码（PKG|RoomID|HotelID|ProductID）作为supplierOptionId
     */
    protected function syncToCtrip(
        PkgProduct $product,
        ResHotel $hotel,
        ResRoomType $roomType,
        ?array $dates = null
    ): array {
        // 构建携程产品编码（使用复合编码）
        $compositeCode = $this->buildOtaCode($roomType, $hotel, $product);
        
        // 获取预计算的价格数据
        $query = PkgProductDailyPrice::where('pkg_product_id', $product->id)
            ->where('hotel_id', $hotel->id)
            ->where('room_type_id', $roomType->id)
            ->where('biz_date', '>=', Carbon::today());

        if ($dates !== null) {
            $query->whereIn('biz_date', $dates);
        } else {
            // 使用销售日期范围限制（如果设置了销售日期）
            $dateRange = $product->getEffectiveSaleDateRange();
            if ($dateRange['start'] && $dateRange['end']) {
                $query->whereBetween('biz_date', [
                    $dateRange['start']->format('Y-m-d'),
                    $dateRange['end']->format('Y-m-d')
                ]);
            } else {
                // 如果没有有效的销售日期范围，默认推送未来60天
                $query->where('biz_date', '<=', Carbon::today()->addDays(59));
            }
        }

        $dailyPrices = $query->orderBy('biz_date')->get();

        if ($dailyPrices->isEmpty()) {
            return [
                'success' => false,
                'message' => '没有可推送的价格数据',
            ];
        }

        // 构建携程价格推送请求体
        $bodyData = [
            'sequenceId' => date('Ymd') . str_replace('-', '', \Illuminate\Support\Str::uuid()->toString()),
            'dateType' => 'DATE_REQUIRED',
            'prices' => [],
            'supplierOptionId' => $compositeCode, // 使用复合编码作为产品标识
        ];

        // 转换价格数据（携程价格单位是元，需要除以100）
        foreach ($dailyPrices as $dailyPrice) {
            $bodyData['prices'][] = [
                'salePrice' => floatval($dailyPrice->sale_price), // 已经是元为单位
                'costPrice' => floatval($dailyPrice->cost_price), // 已经是元为单位
                'date' => $dailyPrice->biz_date->format('Y-m-d'),
            ];
        }

        Log::info('PkgProductOtaSyncService: 准备推送价格到携程', [
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'hotel_code' => $hotel->code,
            'room_type_code' => $roomType->code,
            'composite_code' => $compositeCode,
            'price_count' => count($bodyData['prices']),
        ]);

        // 直接使用 CtripClient 推送价格，避免通过 CtripService 的 protected 方法
        $ctripClient = $this->getCtripClient();
        $priceResult = $ctripClient->syncPrice($bodyData);

        // 检查价格推送结果
        $priceResultCode = $priceResult['header']['resultCode'] ?? null;
        $priceSuccess = CtripErrorCodeHelper::isSuccess($priceResultCode);

        if ($priceSuccess) {
            Log::info('PkgProductOtaSyncService: 价格推送成功', [
                'product_id' => $product->id,
                'composite_code' => $compositeCode,
                'price_count' => count($bodyData['prices']),
            ]);
        } else {
            Log::warning('PkgProductOtaSyncService: 价格推送失败', [
                'product_id' => $product->id,
                'composite_code' => $compositeCode,
                'result_code' => $priceResultCode,
                'result_message' => $priceResult['header']['resultMessage'] ?? '未知错误',
            ]);
        }

        // 推送库存
        $stockResult = $this->syncStockToCtrip($product, $hotel, $roomType, $dates, $compositeCode);
        $stockResultCode = $stockResult['header']['resultCode'] ?? null;
        $stockSuccess = CtripErrorCodeHelper::isSuccess($stockResultCode);

        if ($stockSuccess) {
            Log::info('PkgProductOtaSyncService: 库存推送成功', [
                'product_id' => $product->id,
                'composite_code' => $compositeCode,
            ]);
        } else {
            Log::warning('PkgProductOtaSyncService: 库存推送失败', [
                'product_id' => $product->id,
                'composite_code' => $compositeCode,
                'result_code' => $stockResultCode,
                'result_message' => $stockResult['header']['resultMessage'] ?? '未知错误',
            ]);
        }

        // 判断总体推送结果（价格和库存都需要成功）
        $isSuccess = $priceSuccess && $stockSuccess;

        // 构建结果消息
        $messages = [];
        if (!$priceSuccess) {
            $messages[] = '价格推送失败：' . CtripErrorCodeHelper::getErrorMessage(
                $priceResultCode,
                $priceResult['header']['resultMessage'] ?? null
            );
        }
        if (!$stockSuccess) {
            $messages[] = '库存推送失败：' . CtripErrorCodeHelper::getErrorMessage(
                $stockResultCode,
                $stockResult['header']['resultMessage'] ?? null
            );
        }

        $message = $isSuccess 
            ? '价格和库存推送成功' 
            : implode('；', $messages);

        return [
            'success' => $isSuccess,
            'message' => $message,
            'price_result' => $priceResult,
            'stock_result' => $stockResult,
        ];
    }

    /**
     * 推送库存到携程
     * 
     * @param PkgProduct $product 打包产品
     * @param ResHotel $hotel 酒店
     * @param ResRoomType $roomType 房型
     * @param array|null $dates 指定日期数组
     * @param string $compositeCode 复合编码
     * @return array
     */
    protected function syncStockToCtrip(
        PkgProduct $product,
        ResHotel $hotel,
        ResRoomType $roomType,
        ?array $dates = null,
        string $compositeCode
    ): array {
        try {
            // 获取库存数据（从 res_hotel_daily_stock 表）
            $query = ResHotelDailyStock::where('hotel_id', $hotel->id)
                ->where('room_type_id', $roomType->id)
                ->where('biz_date', '>=', Carbon::today());

            if ($dates !== null) {
                $query->whereIn('biz_date', $dates);
            } else {
                // 使用销售日期范围限制（如果设置了销售日期）
                $dateRange = $product->getEffectiveSaleDateRange();
                if ($dateRange['start'] && $dateRange['end']) {
                    $query->whereBetween('biz_date', [
                        $dateRange['start']->format('Y-m-d'),
                        $dateRange['end']->format('Y-m-d')
                    ]);
                } else {
                    // 如果没有有效的销售日期范围，默认推送未来60天
                    $query->where('biz_date', '<=', Carbon::today()->addDays(59));
                }
            }

            $dailyStocks = $query->orderBy('biz_date')->get();

            if ($dailyStocks->isEmpty()) {
                Log::warning('PkgProductOtaSyncService: 没有库存数据可推送', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
                return [
                    'header' => [
                        'resultCode' => '9999',
                        'resultMessage' => '没有库存数据',
                    ],
                ];
            }

            // 检查产品入住天数（如果需要连续入住，需要确保连续日期的库存都满足）
            $stayDays = $product->stay_days ?? 1;
            $inventoryByDate = [];

            foreach ($dailyStocks as $stock) {
                $date = $stock->biz_date->format('Y-m-d');
                
                // 检查销售日期范围
                if (!$product->isDateInSaleRange($stock->biz_date)) {
                    // 不在销售日期范围内，库存设为0
                    $inventoryByDate[$date] = 0;
                    continue;
                }

                // 获取可用库存
                $availableStock = $stock->stock_available ?? ($stock->stock_total - $stock->stock_sold);
                $inventoryByDate[$date] = max(0, (int) $availableStock);
            }

            // 如果产品设置了入住天数（stay_days > 1），需要检查连续入住天数的库存
            if ($stayDays > 1) {
                $adjustedInventoryByDate = [];
                foreach ($inventoryByDate as $date => $quantity) {
                    $dateObj = Carbon::parse($date);
                    $canAccommodate = true;
                    
                    // 检查连续入住天数内的所有日期
                    for ($i = 0; $i < $stayDays; $i++) {
                        $checkDate = $dateObj->copy()->addDays($i)->format('Y-m-d');
                        
                        // 如果该日期不在当前查询范围内，需要查询数据库
                        if (!isset($inventoryByDate[$checkDate])) {
                            $missingStock = ResHotelDailyStock::where('hotel_id', $hotel->id)
                                ->where('room_type_id', $roomType->id)
                                ->where('biz_date', $checkDate)
                                ->first();
                            
                            if (!$missingStock) {
                                $canAccommodate = false;
                                break;
                            }

                            // 检查销售日期范围
                            if (!$product->isDateInSaleRange($missingStock->biz_date)) {
                                $canAccommodate = false;
                                break;
                            }

                            // 检查库存
                            $missingAvailableStock = $missingStock->stock_available ?? ($missingStock->stock_total - $missingStock->stock_sold);
                            if ($missingAvailableStock <= 0) {
                                $canAccommodate = false;
                                break;
                            }
                        } else {
                            // 日期在查询范围内，直接检查
                            if ($inventoryByDate[$checkDate] <= 0) {
                                $canAccommodate = false;
                                break;
                            }
                        }
                    }
                    
                    // 如果满足连续入住，使用该日期的实际库存；否则设为0
                    $adjustedInventoryByDate[$date] = $canAccommodate ? $quantity : 0;
                }
                
                $inventoryByDate = $adjustedInventoryByDate;
            }

            // 构建携程库存推送请求体
            $bodyData = [
                'sequenceId' => date('Ymd') . str_replace('-', '', \Illuminate\Support\Str::uuid()->toString()),
                'dateType' => 'DATE_REQUIRED',
                'inventorys' => [],
                'supplierOptionId' => $compositeCode, // 使用复合编码作为产品标识
            ];

            // 转换库存数据
            foreach ($inventoryByDate as $date => $quantity) {
                $quantityInt = max(0, (int) $quantity);
                
                $bodyData['inventorys'][] = [
                    'quantity' => $quantityInt,
                    'date' => $date,
                ];
            }

            if (empty($bodyData['inventorys'])) {
                return [
                    'header' => [
                        'resultCode' => '9999',
                        'resultMessage' => '没有有效的库存数据',
                    ],
                ];
            }

            Log::info('PkgProductOtaSyncService: 准备推送库存到携程', [
                'product_id' => $product->id,
                'composite_code' => $compositeCode,
                'inventory_count' => count($bodyData['inventorys']),
            ]);

            // 直接使用 CtripClient 推送库存，避免通过 CtripService 的 protected 方法
            $ctripClient = $this->getCtripClient();
            $result = $ctripClient->syncStock($bodyData);

            return $result;

        } catch (\Exception $e) {
            Log::error('PkgProductOtaSyncService: 推送库存失败', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'header' => [
                    'resultCode' => '9999',
                    'resultMessage' => '推送库存异常：' . $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * 构建OTA编码
     * 格式：PKG|RoomID|HotelID|ProductID
     * 
     * @param ResRoomType $roomType 房型
     * @param ResHotel $hotel 酒店
     * @param PkgProduct $product 产品
     * @return string
     */
    protected function buildOtaCode(
        ResRoomType $roomType,
        ResHotel $hotel,
        PkgProduct $product
    ): string {
        return sprintf(
            'PKG|%s|%s|%s',
            $roomType->code,
            $hotel->code,
            $product->product_code
        );
    }
}

