<?php

namespace App\Http\Controllers;

use App\Models\Pkg\PkgProduct;
use App\Models\Pkg\PkgOtaProduct;
use App\Services\Pkg\PkgProductOtaSyncService;
use App\Jobs\Pkg\SyncProductPricesToOtaJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PkgOtaProductController extends Controller
{
    /**
     * 获取打包产品OTA绑定列表
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'pkg_product_id' => 'required|exists:pkg_products,id',
        ]);

        $pkgOtaProducts = PkgOtaProduct::where('pkg_product_id', $request->pkg_product_id)
            ->with('otaPlatform')
            ->get();

        return response()->json([
            'data' => $pkgOtaProducts,
        ]);
    }

    /**
     * 绑定打包产品到OTA平台（不执行推送）
     */
    public function bindOta(Request $request, PkgProduct $pkgProduct): JsonResponse
    {
        $request->validate([
            'ota_platform_id' => 'required|exists:ota_platforms,id',
        ]);

        try {
            $otaPlatformId = $request->input('ota_platform_id');

            // 检查是否已经绑定过
            $existingOtaProduct = PkgOtaProduct::where('pkg_product_id', $pkgProduct->id)
                ->where('ota_platform_id', $otaPlatformId)
                ->first();

            if ($existingOtaProduct) {
                return response()->json([
                    'success' => false,
                    'message' => '该打包产品已绑定到此OTA平台',
                ], 422);
            }

            // 创建绑定记录（不执行推送）
            $otaProduct = PkgOtaProduct::create([
                'pkg_product_id' => $pkgProduct->id,
                'ota_platform_id' => $otaPlatformId,
                'ota_product_id' => null, // 绑定时为空
                'is_active' => true,
                'pushed_at' => null, // 绑定时为空
            ]);

            return response()->json([
                'success' => true,
                'message' => '绑定成功',
                'data' => [
                    'ota_product' => $otaProduct->load('otaPlatform'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('绑定打包产品到OTA失败', [
                'pkg_product_id' => $pkgProduct->id,
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
     * 推送打包产品价格到OTA平台
     * 
     * 注意：通过环境变量 ENABLE_PRODUCT_PUSH_ASYNC 控制是否使用异步处理
     */
    public function push(PkgOtaProduct $pkgOtaProduct, Request $request): JsonResponse
    {
        try {
            // 检查是否启用异步处理
            $useAsync = env('ENABLE_PRODUCT_PUSH_ASYNC', false);

            if ($useAsync) {
                return $this->pushAsync($pkgOtaProduct);
            }

            return $this->pushSync($pkgOtaProduct);

        } catch (\Exception $e) {
            Log::error('推送打包产品价格到OTA失败', [
                'pkg_ota_product_id' => $pkgOtaProduct->id,
                'pkg_product_id' => $pkgOtaProduct->pkg_product_id,
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
     * 异步推送方式
     */
    protected function pushAsync(PkgOtaProduct $pkgOtaProduct): JsonResponse
    {
        try {
            // 检查是否已推送
            if ($pkgOtaProduct->pushed_at) {
                Log::info('重新推送打包产品价格到OTA（异步）', [
                    'pkg_ota_product_id' => $pkgOtaProduct->id,
                    'pkg_product_id' => $pkgOtaProduct->pkg_product_id,
                ]);
            }

            $product = $pkgOtaProduct->pkgProduct;
            $otaPlatform = $pkgOtaProduct->otaPlatform;

            if (!$otaPlatform) {
                return response()->json([
                    'success' => false,
                    'message' => 'OTA平台不存在',
                ], 422);
            }

            // 更新推送状态为处理中
            $pkgOtaProduct->update([
                'push_status' => 'processing',
                'push_started_at' => now(),
            ]);

            // 将推送任务放入队列（传递 pkgOtaProductId 以便更新状态）
            SyncProductPricesToOtaJob::dispatch(
                $product->id,
                $otaPlatform->code->value,
                null, // 默认推送未来60天
                $pkgOtaProduct->id // 传递绑定记录ID，用于更新状态
            )->onQueue('ota-push');

            Log::info('打包产品价格推送任务已放入队列', [
                'pkg_ota_product_id' => $pkgOtaProduct->id,
                'pkg_product_id' => $product->id,
                'platform' => $otaPlatform->code->value,
            ]);

            return response()->json([
                'success' => true,
                'message' => '推送任务已提交，正在后台处理中',
                'data' => [
                    'push_status' => 'processing',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('异步推送任务提交失败', [
                'pkg_ota_product_id' => $pkgOtaProduct->id,
                'error' => $e->getMessage(),
            ]);

            // 降级到同步处理
            return $this->pushSync($pkgOtaProduct);
        }
    }

    /**
     * 同步推送方式
     */
    protected function pushSync(PkgOtaProduct $pkgOtaProduct): JsonResponse
    {
        // 检查是否已推送
        if ($pkgOtaProduct->pushed_at) {
            Log::info('重新推送打包产品价格到OTA', [
                'pkg_ota_product_id' => $pkgOtaProduct->id,
                'pkg_product_id' => $pkgOtaProduct->pkg_product_id,
            ]);
        }

        $product = $pkgOtaProduct->pkgProduct;
        $otaPlatform = $pkgOtaProduct->otaPlatform;

        if (!$otaPlatform) {
            return response()->json([
                'success' => false,
                'message' => 'OTA平台不存在',
            ], 422);
        }

        // 更新推送状态为处理中
        $pkgOtaProduct->update([
            'push_status' => 'processing',
            'push_started_at' => now(),
        ]);

        try {
            // 调用推送服务
            $syncService = app(PkgProductOtaSyncService::class);
            $pushResult = $syncService->syncProductPricesToOta(
                $product,
                $otaPlatform->code->value,
                null // 默认推送未来60天
            );

            if ($pushResult['success']) {
                // 更新推送信息
                $pkgOtaProduct->update([
                    'is_active' => true,
                    'pushed_at' => now(),
                    'push_status' => 'success',
                    'push_completed_at' => now(),
                    'push_message' => $pushResult['message'] ?? '推送成功',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => $pushResult['message'] ?? '推送成功',
                    'data' => [
                        'pushed_at' => $pkgOtaProduct->pushed_at,
                        'push_status' => 'success',
                    ],
                ]);
            } else {
                // 推送失败
                $pkgOtaProduct->update([
                    'push_status' => 'failed',
                    'push_completed_at' => now(),
                    'push_message' => $pushResult['message'] ?? '推送失败',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $pushResult['message'] ?? '推送失败',
                    'errors' => $pushResult['details'] ?? [],
                ], 422);
            }
        } catch (\Exception $e) {
            // 更新失败状态
            $pkgOtaProduct->update([
                'push_status' => 'failed',
                'push_completed_at' => now(),
                'push_message' => $e->getMessage(),
            ]);

            Log::error('同步推送打包产品价格失败', [
                'pkg_ota_product_id' => $pkgOtaProduct->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '推送失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 更新OTA产品绑定（编辑）
     */
    public function update(Request $request, PkgOtaProduct $pkgOtaProduct): JsonResponse
    {
        $request->validate([
            'ota_platform_id' => 'sometimes|exists:ota_platforms,id',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            // 如果已推送，不允许修改平台
            if ($pkgOtaProduct->pushed_at && $request->has('ota_platform_id')) {
                $newPlatformId = $request->input('ota_platform_id');
                if ($pkgOtaProduct->ota_platform_id != $newPlatformId) {
                    return response()->json([
                        'success' => false,
                        'message' => '已推送的记录不允许修改OTA平台',
                    ], 422);
                }
            }

            // 检查新平台是否已绑定
            if ($request->has('ota_platform_id')) {
                $newPlatformId = $request->input('ota_platform_id');
                if ($pkgOtaProduct->ota_platform_id != $newPlatformId) {
                    $existing = PkgOtaProduct::where('pkg_product_id', $pkgOtaProduct->pkg_product_id)
                        ->where('ota_platform_id', $newPlatformId)
                        ->where('id', '!=', $pkgOtaProduct->id)
                        ->first();
                    
                    if ($existing) {
                        return response()->json([
                            'success' => false,
                            'message' => '该打包产品已绑定到此OTA平台',
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

            $pkgOtaProduct->update($updateData);

            return response()->json([
                'success' => true,
                'message' => '更新成功',
                'data' => $pkgOtaProduct->fresh(['otaPlatform']),
            ]);
        } catch (\Exception $e) {
            Log::error('更新打包产品OTA绑定失败', [
                'pkg_ota_product_id' => $pkgOtaProduct->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '更新失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 删除OTA产品绑定（不调用OTA API取消推送）
     */
    public function destroy(PkgOtaProduct $pkgOtaProduct): JsonResponse
    {
        try {
            // 只删除本地绑定记录，不调用OTA API取消推送
            $pkgOtaProduct->delete();

            return response()->json([
                'success' => true,
                'message' => '删除成功',
            ]);
        } catch (\Exception $e) {
            Log::error('删除打包产品OTA绑定失败', [
                'pkg_ota_product_id' => $pkgOtaProduct->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '删除失败：' . $e->getMessage(),
            ], 500);
        }
    }
}

