<?php

namespace App\Jobs;

use App\Helpers\CtripErrorCodeHelper;
use App\Models\OtaProduct;
use App\Services\OTA\CtripService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Log;

/**
 * 异步推送产品到OTA平台
 */
class PushProductToOtaJob implements ShouldQueue
{
    use Queueable, FoundationQueueable;

    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 600; // 10分钟，因为可能需要推送大量数据

    /**
     * @param int $otaProductId OTA产品ID
     */
    public function __construct(
        public int $otaProductId
    ) {}

    public function handle(
        CtripService $ctripService
    ): void {
        try {
            Log::info('PushProductToOtaJob 开始执行', [
                'ota_product_id' => $this->otaProductId,
            ]);

            $otaProduct = OtaProduct::with(['product', 'otaPlatform'])->find($this->otaProductId);

            if (!$otaProduct) {
                Log::warning('PushProductToOtaJob: OTA产品不存在', [
                    'ota_product_id' => $this->otaProductId,
                ]);
                return;
            }

            $product = $otaProduct->product;
            $otaPlatform = $otaProduct->otaPlatform;

            if (!$product || !$otaPlatform) {
                Log::warning('PushProductToOtaJob: 产品或平台不存在', [
                    'ota_product_id' => $this->otaProductId,
                    'has_product' => $product !== null,
                    'has_platform' => $otaPlatform !== null,
                ]);
                return;
            }

            // 更新推送状态为处理中（如果字段存在）
            $updateData = [
                'push_status' => 'processing',
                'push_started_at' => now(),
            ];
            $otaProduct->update($updateData); // Laravel 会自动忽略不存在的字段

            // 调用对应的OTA服务推送产品
            $pushResult = $this->pushProductToPlatform($product, $otaPlatform, $ctripService);

            if ($pushResult['success']) {
                // 更新推送信息
                $otaProduct->update([
                    'ota_product_id' => $pushResult['ota_product_id'] ?? $otaProduct->ota_product_id,
                    'is_active' => true,
                    'pushed_at' => now(),
                    'push_status' => 'success',
                    'push_completed_at' => now(),
                    'push_message' => $pushResult['message'] ?? '推送成功',
                ]);

                Log::info('PushProductToOtaJob 执行成功', [
                    'ota_product_id' => $this->otaProductId,
                    'product_id' => $product->id,
                    'platform' => $otaPlatform->code->value,
                ]);
            } else {
                // 推送失败
                $errorMessage = $pushResult['message'] ?? '推送失败';
                
                $otaProduct->update([
                    'push_status' => 'failed',
                    'push_completed_at' => now(),
                    'push_message' => $errorMessage,
                ]);

                Log::error('PushProductToOtaJob 执行失败', [
                    'ota_product_id' => $this->otaProductId,
                    'product_id' => $product->id,
                    'platform' => $otaPlatform->code->value,
                    'error' => $errorMessage,
                ]);

                throw new \Exception($errorMessage); // 触发重试
            }

        } catch (\Exception $e) {
            // 更新推送状态为失败
            $otaProduct = OtaProduct::find($this->otaProductId);
            if ($otaProduct) {
                $otaProduct->update([
                    'push_status' => 'failed',
                    'push_completed_at' => now(),
                    'push_message' => $e->getMessage(),
                ]);
            }

            Log::error('PushProductToOtaJob 处理异常', [
                'ota_product_id' => $this->otaProductId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // 触发重试
        }
    }

    /**
     * 根据OTA平台类型推送产品
     */
    protected function pushProductToPlatform(
        \App\Models\Product $product,
        \App\Models\OtaPlatform $otaPlatform,
        CtripService $ctripService
    ): array {
        return match ($otaPlatform->code->value) {
            'ctrip' => $this->pushToCtrip($product, $ctripService),
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
    protected function pushToCtrip(\App\Models\Product $product, CtripService $ctripService): array
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
            $missingCodes = [];

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
                $priceResult = $ctripService->syncProductPriceByCombo(
                    $product,
                    $hotel,
                    $roomType,
                    null, // 全量推送
                    'DATE_REQUIRED'
                );

                // 推送库存
                $stockResult = $ctripService->syncProductStockByCombo(
                    $product,
                    $hotel,
                    $roomType,
                    null, // 全量推送
                    'DATE_REQUIRED'
                );

                // 根据携程返回的 resultCode 判断成功
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
    protected function pushToFliggy(\App\Models\Product $product): array
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
     * 任务失败时的处理
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PushProductToOtaJob 执行失败', [
            'ota_product_id' => $this->otaProductId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $otaProduct = OtaProduct::find($this->otaProductId);
        if ($otaProduct) {
            $otaProduct->update([
                'push_status' => 'failed',
                'push_completed_at' => now(),
                'push_message' => $exception->getMessage(),
            ]);
        }
    }
}

