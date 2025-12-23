<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OtaProduct;
use App\Models\Product;
use App\Services\OTA\CtripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OtaProductController extends Controller
{
    public function __construct(
        protected CtripService $ctripService
    ) {}

    /**
     * 推送产品到OTA平台
     */
    public function pushToOta(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'ota_platform_ids' => 'required|array',
            'ota_platform_ids.*' => 'exists:ota_platforms,id',
        ]);

        try {
            DB::beginTransaction();

            $otaPlatformIds = $request->input('ota_platform_ids');
            $results = [];

            foreach ($otaPlatformIds as $otaPlatformId) {
                // 检查是否已经推送过
                $existingOtaProduct = OtaProduct::where('product_id', $product->id)
                    ->where('ota_platform_id', $otaPlatformId)
                    ->first();

                if ($existingOtaProduct) {
                    // 如果已存在，更新推送时间
                    $existingOtaProduct->update([
                        'is_active' => true,
                        'pushed_at' => now(),
                    ]);
                    $results[] = [
                        'ota_platform_id' => $otaPlatformId,
                        'status' => 'updated',
                        'ota_product' => $existingOtaProduct,
                    ];
                    continue;
                }

                // 根据OTA平台类型调用不同的服务
                $otaPlatform = \App\Models\OtaPlatform::find($otaPlatformId);
                
                if (!$otaPlatform) {
                    $results[] = [
                        'ota_platform_id' => $otaPlatformId,
                        'status' => 'failed',
                        'message' => 'OTA平台不存在',
                    ];
                    continue;
                }

                // 调用对应的OTA服务推送产品
                $pushResult = $this->pushProductToPlatform($product, $otaPlatform);

                if ($pushResult['success']) {
                    // 创建OTA产品记录
                    $otaProduct = OtaProduct::create([
                        'product_id' => $product->id,
                        'ota_platform_id' => $otaPlatformId,
                        'ota_product_id' => $pushResult['ota_product_id'] ?? null,
                        'is_active' => true,
                        'pushed_at' => now(),
                    ]);

                    $results[] = [
                        'ota_platform_id' => $otaPlatformId,
                        'status' => 'success',
                        'ota_product' => $otaProduct,
                    ];
                } else {
                    $results[] = [
                        'ota_platform_id' => $otaPlatformId,
                        'status' => 'failed',
                        'message' => $pushResult['message'] ?? '推送失败',
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '推送完成',
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('推送产品到OTA失败', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '推送失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 根据OTA平台类型推送产品
     */
    protected function pushProductToPlatform(Product $product, \App\Models\OtaPlatform $otaPlatform): array
    {
        return match ($otaPlatform->code->value) {
            'ctrip' => $this->pushToCtrip($product),
            'fliggy' => $this->pushToFliggy($product),
            default => [
                'success' => false,
                'message' => '不支持的OTA平台',
            ],
        };
    }

    /**
     * 推送到携程
     */
    protected function pushToCtrip(Product $product): array
    {
        try {
            // 检查产品是否关联酒店和房型
            $prices = $product->prices()->with(['roomType.hotel'])->get();
            
            if ($prices->isEmpty()) {
                return [
                    'success' => false,
                    'message' => '产品未关联酒店和房型，请先选择酒店和房型',
                ];
            }

            // 检查编码
            if (empty($product->code)) {
                return [
                    'success' => false,
                    'message' => '产品编码为空，请先设置产品编码',
                ];
            }

            // 获取所有"产品-酒店-房型"组合
            $combos = [];
            $seen = [];

            foreach ($prices as $price) {
                $roomType = $price->roomType;
                if (!$roomType) {
                    continue;
                }

                $hotel = $roomType->hotel;
                if (!$hotel) {
                    continue;
                }

                // 检查编码
                if (empty($hotel->code) || empty($roomType->code)) {
                    continue;
                }

                $key = "{$hotel->id}_{$roomType->id}";
                if (!isset($seen[$key])) {
                    $combos[] = [
                        'hotel' => $hotel,
                        'room_type' => $roomType,
                    ];
                    $seen[$key] = true;
                }
            }

            if (empty($combos)) {
                return [
                    'success' => false,
                    'message' => '产品未关联有效的酒店和房型（编码为空）',
                ];
            }

            // 为每个组合推送价格和库存
            $successCount = 0;
            $failCount = 0;
            $errors = [];

            foreach ($combos as $combo) {
                $hotel = $combo['hotel'];
                $roomType = $combo['room_type'];

                // 推送价格
                $priceResult = $this->ctripService->syncProductPriceByCombo(
                    $product,
                    $hotel,
                    $roomType,
                    null,
                    'DATE_REQUIRED'
                );

                // 推送库存
                $stockResult = $this->ctripService->syncProductStockByCombo(
                    $product,
                    $hotel,
                    $roomType,
                    null,
                    'DATE_REQUIRED'
                );

                if (($priceResult['success'] ?? false) && ($stockResult['success'] ?? false)) {
                    $successCount++;
                } else {
                    $failCount++;
                    $errors[] = "酒店 {$hotel->name} 房型 {$roomType->name}: " . 
                        ($priceResult['message'] ?? '价格推送失败') . '; ' . 
                        ($stockResult['message'] ?? '库存推送失败');
                }
            }

            if ($failCount > 0) {
                return [
                    'success' => false,
                    'message' => "部分推送失败：成功 {$successCount} 个，失败 {$failCount} 个",
                    'errors' => $errors,
                ];
            }

            Log::info('推送产品到携程成功', [
                'product_id' => $product->id,
                'combo_count' => count($combos),
            ]);

            return [
                'success' => true,
                'ota_product_id' => 'CTRIP_' . $product->id,
                'message' => "推送成功，共推送 {$successCount} 个组合",
            ];
        } catch (\Exception $e) {
            Log::error('推送到携程失败', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 推送到飞猪
     */
    protected function pushToFliggy(Product $product): array
    {
        try {
            // TODO: 实现飞猪产品推送
            Log::info('推送产品到飞猪', [
                'product_id' => $product->id,
            ]);

            return [
                'success' => true,
                'ota_product_id' => 'FLIGGY_' . $product->id,
                'message' => '推送成功（待实现实际API调用）',
            ];
        } catch (\Exception $e) {
            Log::error('推送到飞猪失败', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 构建携程产品数据
     */
    protected function buildCtripProductData(Product $product): array
    {
        // 根据携程API文档构建产品数据
        // 这里需要根据实际API文档调整
        return [
            'productId' => $product->code,
            'productName' => $product->name,
            'description' => $product->description,
            'scenicSpot' => $product->scenicSpot->name ?? '',
            // 更多字段根据API文档添加
        ];
    }

    /**
     * 删除OTA产品推送
     */
    public function destroy(OtaProduct $otaProduct): JsonResponse
    {
        try {
            // TODO: 如果需要，可以调用OTA API删除产品
            // 目前只删除本地记录
            $otaProduct->delete();

            return response()->json([
                'success' => true,
                'message' => '删除成功',
            ]);
        } catch (\Exception $e) {
            Log::error('删除OTA产品失败', [
                'ota_product_id' => $otaProduct->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '删除失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 更新OTA产品状态
     */
    public function updateStatus(Request $request, OtaProduct $otaProduct): JsonResponse
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        try {
            $otaProduct->update([
                'is_active' => $request->boolean('is_active'),
            ]);

            return response()->json([
                'success' => true,
                'message' => '更新成功',
                'data' => $otaProduct,
            ]);
        } catch (\Exception $e) {
            Log::error('更新OTA产品状态失败', [
                'ota_product_id' => $otaProduct->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '更新失败：' . $e->getMessage(),
            ], 500);
        }
    }
}

