<?php

namespace App\Http\Controllers;

use App\Models\Res\ResHotelDailyStock;
use App\Models\Pkg\PkgProduct;
use App\Models\Pkg\PkgOtaProduct;
use App\Services\Pkg\PkgProductOtaSyncService;
use App\Jobs\Pkg\SyncProductPricesToOtaJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ResHotelStockPushController extends Controller
{
    /**
     * 推送单个打包酒店价库到OTA
     */
    public function pushStock(ResHotelDailyStock $resHotelDailyStock): JsonResponse
    {
        try {
            // 获取酒店和房型信息
            $hotel = $resHotelDailyStock->hotel;
            $roomType = $resHotelDailyStock->roomType;

            if (!$hotel || !$roomType) {
                return response()->json([
                    'success' => false,
                    'message' => '酒店或房型不存在',
                ], 404);
            }

            // 查找关联的打包产品
            $pkgProducts = PkgProduct::whereHas('hotelRoomTypes', function ($q) use ($hotel, $roomType) {
                $q->where('hotel_id', $hotel->id)
                  ->where('room_type_id', $roomType->id);
            })
            ->where('status', 1) // 只查找启用状态的产品
            ->get();

            if ($pkgProducts->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '该房型没有关联的打包产品',
                ], 400);
            }

            // 检查是否有产品已推送到OTA
            $hasOtaProduct = false;
            $otaProducts = [];
            
            foreach ($pkgProducts as $pkgProduct) {
                $pkgOtaProducts = PkgOtaProduct::where('pkg_product_id', $pkgProduct->id)
                    ->where('is_active', true)
                    ->get();
                
                if ($pkgOtaProducts->isNotEmpty()) {
                    $hasOtaProduct = true;
                    foreach ($pkgOtaProducts as $pkgOtaProduct) {
                        $otaProducts[] = [
                            'pkg_product' => $pkgProduct,
                            'pkg_ota_product' => $pkgOtaProduct,
                            'date' => $resHotelDailyStock->biz_date->format('Y-m-d'),
                        ];
                    }
                }
            }

            if (!$hasOtaProduct) {
                return response()->json([
                    'success' => false,
                    'message' => '该房型关联的打包产品未推送到OTA平台',
                ], 400);
            }

            // 为每个已推送的OTA产品推送该日期的库存
            $pushCount = 0;
            $date = $resHotelDailyStock->biz_date->format('Y-m-d');
            
            foreach ($otaProducts as $otaProductData) {
                $pkgProduct = $otaProductData['pkg_product'];
                $pkgOtaProduct = $otaProductData['pkg_ota_product'];
                $otaPlatform = $pkgOtaProduct->otaPlatform;

                if (!$otaPlatform) {
                    Log::warning('手动推送打包酒店价库到OTA：OTA平台不存在', [
                        'pkg_ota_product_id' => $pkgOtaProduct->id,
                    ]);
                    continue;
                }

                // 异步推送该日期的价格和库存
                SyncProductPricesToOtaJob::dispatch(
                    $pkgProduct->id,
                    $otaPlatform->code->value,
                    [$date], // 只推送该日期
                    $pkgOtaProduct->id
                )->onQueue('ota-push');

                $pushCount++;
            }

            Log::info('手动推送打包酒店价库到OTA', [
                'stock_id' => $resHotelDailyStock->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'date' => $date,
                'pushed_products_count' => $pushCount,
            ]);

            return response()->json([
                'success' => true,
                'message' => "推送任务已提交，正在后台处理中（共 {$pushCount} 个产品）",
                'data' => [
                    'pushed_products_count' => $pushCount,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('手动推送打包酒店价库到OTA失败', [
                'stock_id' => $resHotelDailyStock->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '推送失败：' . $e->getMessage(),
            ], 500);
        }
    }
}

