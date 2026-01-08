<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Resource\ResourceServiceFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 订单核销处理服务
 * 统一处理订单核销状态的更新和OTA推送
 */
class OrderVerificationService
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    /**
     * 处理订单核销状态
     * 
     * @param Order $order 订单
     * @param array $verificationData 核销数据，格式：
     *   [
     *     'status' => string,           // 订单状态：confirmed/verified/cancelled等
     *     'verified_at' => string|null, // 核销时间（ISO 8601格式）
     *     'use_start_date' => string|null, // 使用开始日期
     *     'use_end_date' => string|null,   // 使用结束日期
     *     'use_quantity' => int|null,      // 已使用数量
     *     'passengers' => array,      // 客人信息（可选）
     *     'vouchers' => array,        // 凭证信息（可选）
     *   ]
     * @param string $source 数据来源：'query' 或 'webhook'
     * @param int|null $operatorId 操作人ID（可选）
     * @return array
     */
    public function handleVerificationStatus(
        Order $order, 
        array $verificationData, 
        string $source = 'query',
        ?int $operatorId = null
    ): array {
        try {
            // 检查订单状态是否为已核销
            $status = $verificationData['status'] ?? null;
            
            // 如果状态不是已核销，不需要处理
            if ($status !== OrderStatus::VERIFIED->value) {
                Log::info('订单核销状态处理：订单状态不是已核销，跳过处理', [
                    'order_id' => $order->id,
                    'current_status' => $order->status->value,
                    'query_status' => $status,
                    'source' => $source,
                ]);
                return [
                    'success' => true,
                    'message' => '订单状态不是已核销，无需处理',
                    'data' => ['status' => $status],
                ];
            }

            // 如果订单已经是已核销状态，检查是否需要更新
            if ($order->status === OrderStatus::VERIFIED) {
                Log::info('订单核销状态处理：订单已经是已核销状态', [
                    'order_id' => $order->id,
                    'source' => $source,
                ]);
                
                // 即使状态已经是VERIFIED，仍然触发OTA推送（确保OTA平台状态同步）
                $this->notifyOtaOrderConsumed($order, $verificationData);
                
                return [
                    'success' => true,
                    'message' => '订单已经是已核销状态',
                    'data' => ['status' => $order->status->value],
                ];
            }

            // 检查订单状态是否允许核销（只有CONFIRMED状态的订单才能核销）
            if ($order->status !== OrderStatus::CONFIRMED) {
                Log::warning('订单核销状态处理：订单状态不允许核销', [
                    'order_id' => $order->id,
                    'current_status' => $order->status->value,
                    'source' => $source,
                ]);
                
                return [
                    'success' => false,
                    'message' => '订单状态不允许核销，当前状态：' . $order->status->label(),
                ];
            }

            DB::beginTransaction();

            // 更新订单状态为已核销
            $remark = match($source) {
                'query' => '主动查询：订单已核销',
                'webhook' => 'Webhook推送：订单已核销',
                default => '订单已核销',
            };

            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::VERIFIED,
                $remark,
                $operatorId
            );

            DB::commit();

            // 推送到OTA平台
            try {
                $this->notifyOtaOrderConsumed($order, $verificationData);
            } catch (\Exception $e) {
                Log::warning('订单核销状态处理：通知OTA平台失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
                // 不影响主流程，只记录警告
            }

            Log::info('订单核销状态处理成功', [
                'order_id' => $order->id,
                'source' => $source,
                'status' => $order->status->value,
            ]);

            return [
                'success' => true,
                'message' => '订单核销状态更新成功',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status->value,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('订单核销状态处理失败', [
                'order_id' => $order->id,
                'source' => $source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '订单核销状态处理失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 通知OTA平台订单核销
     * 
     * @param Order $order 订单
     * @param array $verificationData 核销数据
     */
    protected function notifyOtaOrderConsumed(Order $order, array $verificationData): void
    {
        $platform = $order->otaPlatform;
        if (!$platform) {
            Log::warning('订单核销通知：订单没有关联OTA平台', [
                'order_id' => $order->id,
            ]);
            return;
        }

        // 使用NotifyOtaOrderStatusJob推送订单状态到OTA
        // 该Job会根据订单状态（verified）自动调用对应的通知方法（notifyOrderConsumed）
        try {
            \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order)
                ->onQueue('ota-notification');
            
            Log::info('订单核销通知：已派发OTA推送任务', [
                'order_id' => $order->id,
                'platform' => $platform->code->value,
            ]);
        } catch (\Exception $e) {
            Log::error('订单核销通知：派发OTA推送任务失败', [
                'order_id' => $order->id,
                'platform' => $platform->code->value,
                'error' => $e->getMessage(),
            ]);
            // 不抛出异常，避免影响主流程
        }
    }
}

