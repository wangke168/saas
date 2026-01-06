<?php

namespace App\Services\Pkg;

use App\Models\Pkg\PkgProduct;
use App\Models\Pkg\PkgProductDailyPrice;
use App\Models\Res\ResHotel;
use App\Models\Res\ResRoomType;
use App\Services\OTA\CtripService;
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

    public function __construct(CtripService $ctripService)
    {
        $this->ctripService = $ctripService;
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

        // 调用携程服务推送价格
        $result = $this->ctripService->getClient()->syncPrice($bodyData);

        // 检查返回结果
        $resultCode = $result['header']['resultCode'] ?? null;
        $isSuccess = $resultCode === '0000' || $resultCode === '00000';

        if ($isSuccess) {
            Log::info('PkgProductOtaSyncService: 价格推送成功', [
                'product_id' => $product->id,
                'composite_code' => $compositeCode,
                'price_count' => count($bodyData['prices']),
            ]);
        } else {
            Log::warning('PkgProductOtaSyncService: 价格推送失败', [
                'product_id' => $product->id,
                'composite_code' => $compositeCode,
                'result_code' => $resultCode,
                'result_message' => $result['header']['resultMessage'] ?? '未知错误',
            ]);
        }

        return [
            'success' => $isSuccess,
            'message' => $isSuccess 
                ? '推送成功' 
                : ($result['header']['resultMessage'] ?? '推送失败'),
            'result' => $result,
        ];
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

