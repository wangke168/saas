<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\CtripErrorCodeHelper;
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
     * 绑定产品到OTA平台（不执行推送）
     */
    public function bindOta(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'ota_platform_id' => 'required|exists:ota_platforms,id',
        ]);

        try {
            $otaPlatformId = $request->input('ota_platform_id');

            // 检查是否已经绑定过
            $existingOtaProduct = OtaProduct::where('product_id', $product->id)
                ->where('ota_platform_id', $otaPlatformId)
                ->first();

            if ($existingOtaProduct) {
                return response()->json([
                    'success' => false,
                    'message' => '该产品已绑定到此OTA平台',
                ], 422);
            }

            // 创建绑定记录（不执行推送）
            $otaProduct = OtaProduct::create([
                'product_id' => $product->id,
                'ota_platform_id' => $otaPlatformId,
                'ota_product_id' => null, // 绑定时为空
                'is_active' => true,
                'pushed_at' => null, // 绑定时为空
            ]);

            return response()->json([
                'success' => true,
                'message' => '绑定成功',
                'data' => [
                    'ota_product' => $otaProduct,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('绑定产品到OTA失败', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '绑定失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 推送产品到OTA平台
     * 
     * 注意：通过环境变量 ENABLE_PRODUCT_PUSH_ASYNC 控制是否使用异步处理
     * - true: 使用异步队列处理（推荐，适合大数据量）
     * - false: 使用同步处理（默认，保持向后兼容）
     */
    public function push(OtaProduct $otaProduct): JsonResponse
    {
        try {
            // 检查是否启用异步处理
            $useAsync = env('ENABLE_PRODUCT_PUSH_ASYNC', false);

            if ($useAsync) {
                // 使用异步处理方式
                return $this->pushAsync($otaProduct);
            }

            // 使用原有的同步处理方式（保持向后兼容）
            return $this->pushSync($otaProduct);

        } catch (\Exception $e) {
            Log::error('推送产品到OTA失败', [
                'ota_product_id' => $otaProduct->id,
                'product_id' => $otaProduct->product_id,
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
     * 异步推送方式（新功能）
     * 立即返回，后台异步处理
     */
    protected function pushAsync(OtaProduct $otaProduct): JsonResponse
    {
        try {
            // 检查是否已推送
            if ($otaProduct->pushed_at) {
                Log::info('重新推送产品到OTA（异步）', [
                    'ota_product_id' => $otaProduct->id,
                    'product_id' => $otaProduct->product_id,
                ]);
            }

            $product = $otaProduct->product;
            $otaPlatform = $otaProduct->otaPlatform;

            if (!$otaPlatform) {
                return response()->json([
                    'success' => false,
                    'message' => 'OTA平台不存在',
                ], 422);
            }

            // 更新推送状态为处理中
            $otaProduct->update([
                'push_status' => 'processing',
                'push_started_at' => now(),
            ]);

            // 将推送任务放入队列
            \App\Jobs\PushProductToOtaJob::dispatch($otaProduct->id)
                ->onQueue('ota-push');

            Log::info('产品推送任务已放入队列', [
                'ota_product_id' => $otaProduct->id,
                'product_id' => $product->id,
                'platform' => $otaPlatform->code->value,
            ]);

            return response()->json([
                'success' => true,
                'message' => '推送任务已提交，正在后台处理中',
                'data' => [
                    'ota_product_id' => $otaProduct->ota_product_id,
                    'pushed_at' => $otaProduct->pushed_at,
                    'push_status' => 'processing',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('异步推送任务提交失败', [
                'ota_product_id' => $otaProduct->id,
                'error' => $e->getMessage(),
            ]);

            // 降级到同步处理
            return $this->pushSync($otaProduct);
        }
    }

    /**
     * 同步推送方式（原有逻辑，保持向后兼容）
     */
    protected function pushSync(OtaProduct $otaProduct): JsonResponse
    {
        // 检查是否已推送
        if ($otaProduct->pushed_at) {
            // 已推送，执行重新推送
            Log::info('重新推送产品到OTA', [
                'ota_product_id' => $otaProduct->id,
                'product_id' => $otaProduct->product_id,
            ]);
        }

        $product = $otaProduct->product;
        $otaPlatform = $otaProduct->otaPlatform;

        if (!$otaPlatform) {
            return response()->json([
                'success' => false,
                'message' => 'OTA平台不存在',
            ], 422);
        }

        // 调用对应的OTA服务推送产品
        $pushResult = $this->pushProductToPlatform($product, $otaPlatform);

        if ($pushResult['success']) {
            // 更新推送信息
            $otaProduct->update([
                'ota_product_id' => $pushResult['ota_product_id'] ?? $otaProduct->ota_product_id,
                'is_active' => true,
                'pushed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => '推送成功',
                'data' => [
                    'ota_product_id' => $otaProduct->ota_product_id,
                    'pushed_at' => $otaProduct->pushed_at,
                ],
            ]);
        } else {
            // 推送失败，但保留绑定关系
            $errorMessage = $pushResult['message'] ?? '推送失败';
            
            // 如果是网络错误，提供更详细的提示
            if (str_contains($errorMessage, 'DNS解析失败') || str_contains($errorMessage, 'Could not resolve host')) {
                $errorMessage .= '。提示：命令行可以成功但Web请求失败，可能是PHP-FPM的网络配置问题，请检查服务器DNS配置或网络策略。';
            }
            
            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'errors' => $pushResult['errors'] ?? [],
            ], 422);
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
            $missingCodes = []; // 记录缺少编码的酒店和房型

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
                $missingCodeParts = [];
                if (empty($hotel->code)) {
                    $missingCodeParts[] = "酒店[{$hotel->name}]编码";
                }
                if (empty($roomType->code)) {
                    $missingCodeParts[] = "房型[{$roomType->name}]编码";
                }
                
                if (!empty($missingCodeParts)) {
                    $missingCodes[] = "{$hotel->name} - {$roomType->name}：" . implode('、', $missingCodeParts);
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
                $errorMessage = '产品未关联有效的酒店和房型（编码为空）';
                if (!empty($missingCodes)) {
                    $errorMessage .= '。缺少编码的酒店/房型：' . implode('；', array_slice($missingCodes, 0, 5));
                    if (count($missingCodes) > 5) {
                        $errorMessage .= '等' . count($missingCodes) . '个';
                    }
                }
                return [
                    'success' => false,
                    'message' => $errorMessage,
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

                // 根据携程返回的 resultCode 判断成功（0000 表示成功）
                $priceResultCode = $priceResult['header']['resultCode'] ?? null;
                $stockResultCode = $stockResult['header']['resultCode'] ?? null;

                $priceSuccess = CtripErrorCodeHelper::isSuccess($priceResultCode);
                $stockSuccess = CtripErrorCodeHelper::isSuccess($stockResultCode);

                if ($priceSuccess && $stockSuccess) {
                    $successCount++;
                } else {
                    $failCount++;
                    $priceError = $priceSuccess 
                        ? '价格推送成功' 
                        : CtripErrorCodeHelper::getErrorMessage(
                            $priceResultCode, 
                            $priceResult['header']['resultMessage'] ?? null
                        );
                    $stockError = $stockSuccess 
                        ? '库存推送成功' 
                        : CtripErrorCodeHelper::getErrorMessage(
                            $stockResultCode, 
                            $stockResult['header']['resultMessage'] ?? null
                        );
                    $errors[] = "酒店 {$hotel->name} 房型 {$roomType->name}: {$priceError}; {$stockError}";
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
     * 删除OTA产品绑定（不调用OTA API取消推送）
     */
    public function destroy(OtaProduct $otaProduct): JsonResponse
    {
        try {
            // 只删除本地绑定记录，不调用OTA API取消推送
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
     * 更新OTA产品（编辑）
     */
    public function update(Request $request, OtaProduct $otaProduct): JsonResponse
    {
        $request->validate([
            'ota_platform_id' => 'sometimes|exists:ota_platforms,id',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            // 如果已推送，不允许修改平台
            if ($otaProduct->pushed_at && $request->has('ota_platform_id')) {
                $newPlatformId = $request->input('ota_platform_id');
                if ($otaProduct->ota_platform_id != $newPlatformId) {
                    return response()->json([
                        'success' => false,
                        'message' => '已推送的记录不允许修改OTA平台',
                    ], 422);
                }
            }

            // 检查新平台是否已绑定
            if ($request->has('ota_platform_id')) {
                $newPlatformId = $request->input('ota_platform_id');
                if ($otaProduct->ota_platform_id != $newPlatformId) {
                    $existing = OtaProduct::where('product_id', $otaProduct->product_id)
                        ->where('ota_platform_id', $newPlatformId)
                        ->where('id', '!=', $otaProduct->id)
                        ->first();
                    
                    if ($existing) {
                        return response()->json([
                            'success' => false,
                            'message' => '该产品已绑定到此OTA平台',
                        ], 422);
                    }
                }
            }

            $updateData = [];
            if ($request->has('ota_platform_id')) {
                $updateData['ota_platform_id'] = $request->input('ota_platform_id');
            }
            if ($request->has('is_active')) {
                $updateData['is_active'] = $request->boolean('is_active');
            }

            $otaProduct->update($updateData);

            return response()->json([
                'success' => true,
                'message' => '更新成功',
                'data' => $otaProduct->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('更新OTA产品失败', [
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


                            $priceResult['header']['resultMessage'] ?? null
                        );
                    $stockError = $stockSuccess 
                        ? '库存推送成功' 
                        : CtripErrorCodeHelper::getErrorMessage(
                            $stockResultCode, 
                            $stockResult['header']['resultMessage'] ?? null
                        );
                    $errors[] = "酒店 {$hotel->name} 房型 {$roomType->name}: {$priceError}; {$stockError}";
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
     * 删除OTA产品绑定（不调用OTA API取消推送）
     */
    public function destroy(OtaProduct $otaProduct): JsonResponse
    {
        try {
            // 只删除本地绑定记录，不调用OTA API取消推送
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
     * 更新OTA产品（编辑）
     */
    public function update(Request $request, OtaProduct $otaProduct): JsonResponse
    {
        $request->validate([
            'ota_platform_id' => 'sometimes|exists:ota_platforms,id',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            // 如果已推送，不允许修改平台
            if ($otaProduct->pushed_at && $request->has('ota_platform_id')) {
                $newPlatformId = $request->input('ota_platform_id');
                if ($otaProduct->ota_platform_id != $newPlatformId) {
                    return response()->json([
                        'success' => false,
                        'message' => '已推送的记录不允许修改OTA平台',
                    ], 422);
                }
            }

            // 检查新平台是否已绑定
            if ($request->has('ota_platform_id')) {
                $newPlatformId = $request->input('ota_platform_id');
                if ($otaProduct->ota_platform_id != $newPlatformId) {
                    $existing = OtaProduct::where('product_id', $otaProduct->product_id)
                        ->where('ota_platform_id', $newPlatformId)
                        ->where('id', '!=', $otaProduct->id)
                        ->first();
                    
                    if ($existing) {
                        return response()->json([
                            'success' => false,
                            'message' => '该产品已绑定到此OTA平台',
                        ], 422);
                    }
                }
            }

            $updateData = [];
            if ($request->has('ota_platform_id')) {
                $updateData['ota_platform_id'] = $request->input('ota_platform_id');
            }
            if ($request->has('is_active')) {
                $updateData['is_active'] = $request->boolean('is_active');
            }

            $otaProduct->update($updateData);

            return response()->json([
                'success' => true,
                'message' => '更新成功',
                'data' => $otaProduct->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('更新OTA产品失败', [
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


                            $priceResult['header']['resultMessage'] ?? null
                        );
                    $stockError = $stockSuccess 
                        ? '库存推送成功' 
                        : CtripErrorCodeHelper::getErrorMessage(
                            $stockResultCode, 
                            $stockResult['header']['resultMessage'] ?? null
                        );
                    $errors[] = "酒店 {$hotel->name} 房型 {$roomType->name}: {$priceError}; {$stockError}";
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
     * 删除OTA产品绑定（不调用OTA API取消推送）
     */
    public function destroy(OtaProduct $otaProduct): JsonResponse
    {
        try {
            // 只删除本地绑定记录，不调用OTA API取消推送
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
     * 更新OTA产品（编辑）
     */
    public function update(Request $request, OtaProduct $otaProduct): JsonResponse
    {
        $request->validate([
            'ota_platform_id' => 'sometimes|exists:ota_platforms,id',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            // 如果已推送，不允许修改平台
            if ($otaProduct->pushed_at && $request->has('ota_platform_id')) {
                $newPlatformId = $request->input('ota_platform_id');
                if ($otaProduct->ota_platform_id != $newPlatformId) {
                    return response()->json([
                        'success' => false,
                        'message' => '已推送的记录不允许修改OTA平台',
                    ], 422);
                }
            }

            // 检查新平台是否已绑定
            if ($request->has('ota_platform_id')) {
                $newPlatformId = $request->input('ota_platform_id');
                if ($otaProduct->ota_platform_id != $newPlatformId) {
                    $existing = OtaProduct::where('product_id', $otaProduct->product_id)
                        ->where('ota_platform_id', $newPlatformId)
                        ->where('id', '!=', $otaProduct->id)
                        ->first();
                    
                    if ($existing) {
                        return response()->json([
                            'success' => false,
                            'message' => '该产品已绑定到此OTA平台',
                        ], 422);
                    }
                }
            }

            $updateData = [];
            if ($request->has('ota_platform_id')) {
                $updateData['ota_platform_id'] = $request->input('ota_platform_id');
            }
            if ($request->has('is_active')) {
                $updateData['is_active'] = $request->boolean('is_active');
            }

            $otaProduct->update($updateData);

            return response()->json([
                'success' => true,
                'message' => '更新成功',
                'data' => $otaProduct->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('更新OTA产品失败', [
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


                            $priceResult['header']['resultMessage'] ?? null
                        );
                    $stockError = $stockSuccess 
                        ? '库存推送成功' 
                        : CtripErrorCodeHelper::getErrorMessage(
                            $stockResultCode, 
                            $stockResult['header']['resultMessage'] ?? null
                        );
                    $errors[] = "酒店 {$hotel->name} 房型 {$roomType->name}: {$priceError}; {$stockError}";
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
     * 删除OTA产品绑定（不调用OTA API取消推送）
     */
    public function destroy(OtaProduct $otaProduct): JsonResponse
    {
        try {
            // 只删除本地绑定记录，不调用OTA API取消推送
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
     * 更新OTA产品（编辑）
     */
    public function update(Request $request, OtaProduct $otaProduct): JsonResponse
    {
        $request->validate([
            'ota_platform_id' => 'sometimes|exists:ota_platforms,id',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            // 如果已推送，不允许修改平台
            if ($otaProduct->pushed_at && $request->has('ota_platform_id')) {
                $newPlatformId = $request->input('ota_platform_id');
                if ($otaProduct->ota_platform_id != $newPlatformId) {
                    return response()->json([
                        'success' => false,
                        'message' => '已推送的记录不允许修改OTA平台',
                    ], 422);
                }
            }

            // 检查新平台是否已绑定
            if ($request->has('ota_platform_id')) {
                $newPlatformId = $request->input('ota_platform_id');
                if ($otaProduct->ota_platform_id != $newPlatformId) {
                    $existing = OtaProduct::where('product_id', $otaProduct->product_id)
                        ->where('ota_platform_id', $newPlatformId)
                        ->where('id', '!=', $otaProduct->id)
                        ->first();
                    
                    if ($existing) {
                        return response()->json([
                            'success' => false,
                            'message' => '该产品已绑定到此OTA平台',
                        ], 422);
                    }
                }
            }

            $updateData = [];
            if ($request->has('ota_platform_id')) {
                $updateData['ota_platform_id'] = $request->input('ota_platform_id');
            }
            if ($request->has('is_active')) {
                $updateData['is_active'] = $request->boolean('is_active');
            }

            $otaProduct->update($updateData);

            return response()->json([
                'success' => true,
                'message' => '更新成功',
                'data' => $otaProduct->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('更新OTA产品失败', [
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
