<?php

namespace App\Services;

use App\Enums\ExceptionOrderStatus;
use App\Enums\OrderStatus;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Services\OTA\CtripService;
use App\Services\Resource\ResourceServiceFactory;
use App\Services\Resource\ResourceServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 订单操作服务
 * 统一处理订单操作，根据是否直连选择不同的处理方式
 */
class OrderOperationService
{
    public function __construct(
        protected OrderService $orderService,
        protected CtripService $ctripService,
    ) {}

    /**
     * 接单（确认订单）
     * 
     * 注意：系统直连的正常流程中，接单已在 PayPreOrder 中异步处理
     * 此方法主要用于：
     * 1. 异常订单的人工处理（接单失败/超时后，人工重新接单）
     * 2. 非系统直连的人工接单
     * 
     * @param Order $order 订单
     * @param string|null $remark 备注
     * @param int|null $operatorId 操作人ID（人工操作时）
     * @return array
     */
    public function confirmOrder(Order $order, ?string $remark = null, ?int $operatorId = null): array
    {
        // 检查是否是异常订单
        $exceptionOrder = ExceptionOrder::where('order_id', $order->id)
            ->where('status', ExceptionOrderStatus::PENDING)
            ->where('exception_data->operation', 'confirm')
            ->first();

        $resourceService = ResourceServiceFactory::getService($order);

        if ($exceptionOrder && $resourceService) {
            // 异常订单处理：调用资源方接口接单
            return $this->confirmOrderWithResource($order, $resourceService, $remark, $operatorId, $exceptionOrder);
        } else if ($resourceService) {
            // 正常流程：系统直连时，不应该走到这里（已在 PayPreOrder 中异步处理）
            // 这里保留作为兜底逻辑，但记录警告日志
            Log::warning('OrderOperationService::confirmOrder: 系统直连订单不应该走到这里', [
                'order_id' => $order->id,
                'status' => $order->status->value,
            ]);
            return $this->confirmOrderManually($order, $remark, $operatorId);
        } else {
            // 非系统直连：人工操作
            return $this->confirmOrderManually($order, $remark, $operatorId);
        }
    }

    /**
     * 系统直连：接单（用于异常订单处理）
     */
    protected function confirmOrderWithResource(
        Order $order, 
        ResourceServiceInterface $resourceService, 
        ?string $remark,
        ?int $operatorId = null,
        ?ExceptionOrder $exceptionOrder = null
    ): array
    {
        try {
            DB::beginTransaction();

            // 1. 调用资源方接口确认订单
            $result = $resourceService->confirmOrder($order);

            if (!($result['success'] ?? false)) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => $result['message'] ?? '资源方接单失败',
                ];
            }

            // 2. 更新订单状态
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CONFIRMED,
                $remark ?? '异常订单人工处理：资源方接单成功',
                $operatorId
            );

            // 3. 保存资源方订单号（如果返回了）
            if (isset($result['data']['resource_order_no'])) {
                $order->update(['resource_order_no' => $result['data']['resource_order_no']]);
            }

            // 4. 标记异常订单为已处理
            if ($exceptionOrder) {
                $exceptionOrder->update([
                    'status' => ExceptionOrderStatus::RESOLVED,
                    'handler_id' => $operatorId,
                    'resolved_at' => now(),
                ]);
            }

            DB::commit();

            // 5. 通知携程平台（异步）
            try {
                \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
            } catch (\Exception $e) {
                Log::warning('通知携程订单确认失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => '接单成功',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status->value,
                    'resource_order_no' => $order->resource_order_no,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('异常订单接单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '接单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 人工操作：接单
     */
    protected function confirmOrderManually(Order $order, ?string $remark, ?int $operatorId): array
    {
        try {
            DB::beginTransaction();

            // 1. 更新订单状态
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CONFIRMED,
                $remark ?? '人工操作：接单',
                $operatorId
            );

            DB::commit();

            // 2. 通知携程平台（异步）
            try {
                \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
            } catch (\Exception $e) {
                Log::warning('通知携程订单确认失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => '接单成功',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status->value,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('人工接单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '接单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 拒单（拒绝订单）
     * 
     * @param Order $order 订单
     * @param string $reason 拒绝原因
     * @param int|null $operatorId 操作人ID（人工操作时）
     * @return array
     */
    public function rejectOrder(Order $order, string $reason, ?int $operatorId = null): array
    {
        $resourceService = ResourceServiceFactory::getService($order);

        if ($resourceService) {
            // 系统直连：调用资源方接口
            return $this->rejectOrderWithResource($order, $resourceService, $reason);
        } else {
            // 非系统直连：人工操作
            return $this->rejectOrderManually($order, $reason, $operatorId);
        }
    }

    /**
     * 系统直连：拒单
     */
    protected function rejectOrderWithResource(Order $order, ResourceServiceInterface $resourceService, string $reason): array
    {
        try {
            DB::beginTransaction();

            // 1. 调用资源方接口拒绝订单
            $result = $resourceService->rejectOrder($order, $reason);

            if (!($result['success'] ?? false)) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => $result['message'] ?? '资源方拒单失败',
                ];
            }

            // 2. 更新订单状态
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::REJECTED,
                '系统直连：资源方拒单 - ' . $reason,
                null
            );

            DB::commit();

            // 3. 通知携程平台（异步）
            try {
                \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
            } catch (\Exception $e) {
                Log::warning('通知携程订单拒绝失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => '拒单成功',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status->value,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('系统直连拒单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '拒单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 人工操作：拒单
     */
    protected function rejectOrderManually(Order $order, string $reason, ?int $operatorId): array
    {
        try {
            DB::beginTransaction();

            // 1. 更新订单状态
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::REJECTED,
                '人工操作：拒单 - ' . $reason,
                $operatorId
            );

            DB::commit();

            // 2. 通知携程平台（异步）
            try {
                \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
            } catch (\Exception $e) {
                Log::warning('通知携程订单拒绝失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => '拒单成功',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status->value,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('人工拒单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '拒单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 核销订单
     * 
     * @param Order $order 订单
     * @param array $data 核销数据 ['use_start_date' => string, 'use_end_date' => string, 'use_quantity' => int, ...]
     * @param int|null $operatorId 操作人ID（人工操作时）
     * @return array
     */
    public function verifyOrder(Order $order, array $data, ?int $operatorId = null): array
    {
        $resourceService = ResourceServiceFactory::getService($order);

        if ($resourceService) {
            // 系统直连：调用资源方接口
            return $this->verifyOrderWithResource($order, $resourceService, $data);
        } else {
            // 非系统直连：人工操作
            return $this->verifyOrderManually($order, $data, $operatorId);
        }
    }

    /**
     * 系统直连：核销
     */
    protected function verifyOrderWithResource(Order $order, ResourceServiceInterface $resourceService, array $data): array
    {
        try {
            DB::beginTransaction();

            // 1. 调用资源方接口核销订单
            $result = $resourceService->verifyOrder($order, $data);

            if (!($result['success'] ?? false)) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => $result['message'] ?? '资源方核销失败',
                ];
            }

            // 2. 更新订单状态
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::VERIFIED,
                '系统直连：资源方核销成功',
                null
            );

            DB::commit();

            // 3. 通知携程平台（调用核销通知接口）
            try {
                $this->notifyCtripOrderConsumed($order, $data);
            } catch (\Exception $e) {
                Log::warning('通知携程订单核销失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => '核销成功',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status->value,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('系统直连核销失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '核销失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 人工操作：核销
     */
    protected function verifyOrderManually(Order $order, array $data, ?int $operatorId): array
    {
        try {
            DB::beginTransaction();

            // 1. 更新订单状态
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::VERIFIED,
                '人工操作：核销',
                $operatorId
            );

            DB::commit();

            // 2. 通知携程平台（调用核销通知接口）
            try {
                $this->notifyCtripOrderConsumed($order, $data);
            } catch (\Exception $e) {
                Log::warning('通知携程订单核销失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => '核销成功',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status->value,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('人工核销失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '核销失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 取消订单（只能由携程发起，在 CtripController 中处理）
     * 
     * 注意：系统直连的正常流程中，取消已在 CancelOrder 中异步处理
     * 此方法主要用于：
     * 1. 异常订单的人工处理（取消失败/超时后，人工同意或拒绝取消）
     * 2. 非系统直连的人工取消处理
     * 
     * @param Order $order 订单
     * @param string $reason 取消原因
     * @param int|null $operatorId 操作人ID（人工操作时）
     * @param bool $approve 是否同意取消（true=同意，false=拒绝）
     * @return array
     */
    public function cancelOrder(Order $order, string $reason, ?int $operatorId = null, bool $approve = true): array
    {
        // 检查是否是异常订单
        $exceptionOrder = ExceptionOrder::where('order_id', $order->id)
            ->where('status', ExceptionOrderStatus::PENDING)
            ->where('exception_data->operation', 'cancel')
            ->first();

        $resourceService = ResourceServiceFactory::getService($order);

        if ($exceptionOrder && $resourceService && $approve) {
            // 异常订单处理：调用资源方接口同意取消
            return $this->cancelOrderWithResource($order, $resourceService, $reason, $operatorId, $exceptionOrder);
        } else if ($exceptionOrder && !$approve) {
            // 异常订单处理：拒绝取消（不调用资源方接口）
            return $this->rejectCancelManually($order, $reason, $operatorId, $exceptionOrder);
        } else if ($resourceService) {
            // 正常流程：系统直连时，不应该走到这里（已在 CancelOrder 中异步处理）
            // 这里保留作为兜底逻辑，但记录警告日志
            Log::warning('OrderOperationService::cancelOrder: 系统直连订单不应该走到这里', [
                'order_id' => $order->id,
                'status' => $order->status->value,
            ]);
            return $this->cancelOrderManually($order, $reason, $operatorId);
        } else {
            // 非系统直连：人工操作
            if ($approve) {
                return $this->cancelOrderManually($order, $reason, $operatorId);
            } else {
                return $this->rejectCancelManually($order, $reason, $operatorId);
            }
        }
    }

    /**
     * 系统直连：取消订单（用于异常订单处理）
     */
    protected function cancelOrderWithResource(
        Order $order, 
        ResourceServiceInterface $resourceService, 
        string $reason,
        ?int $operatorId = null,
        ?ExceptionOrder $exceptionOrder = null
    ): array
    {
        try {
            DB::beginTransaction();

            // 1. 调用资源方接口取消订单
            $result = $resourceService->cancelOrder($order, $reason);

            if (!($result['success'] ?? false)) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => $result['message'] ?? '资源方取消订单失败',
                ];
            }

            // 2. 更新订单状态
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                '异常订单人工处理：资源方取消订单 - ' . $reason,
                $operatorId
            );

            // 3. 释放库存
            try {
                $stayDays = $order->product->stay_days ?? 1;
                $dates = app(InventoryService::class)->getDateRange(
                    $order->check_in_date->format('Y-m-d'),
                    $stayDays
                );
                app(InventoryService::class)->releaseInventoryForDates(
                    $order->room_type_id,
                    $dates,
                    $order->room_count
                );
            } catch (\Exception $e) {
                Log::warning('异常订单取消时释放库存失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // 4. 标记异常订单为已处理
            if ($exceptionOrder) {
                $exceptionOrder->update([
                    'status' => ExceptionOrderStatus::RESOLVED,
                    'handler_id' => $operatorId,
                    'resolved_at' => now(),
                ]);
            }

            DB::commit();

            // 5. 通知携程平台（异步）
            try {
                \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
            } catch (\Exception $e) {
                Log::warning('通知携程订单取消失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => '取消订单成功',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status->value,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('异常订单取消失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '取消订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 人工操作：同意取消订单
     */
    protected function cancelOrderManually(Order $order, string $reason, ?int $operatorId = null): array
    {
        try {
            DB::beginTransaction();

            // 1. 更新订单状态
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                '人工操作：同意取消订单 - ' . $reason,
                $operatorId
            );

            // 2. 释放库存
            try {
                $stayDays = $order->product->stay_days ?? 1;
                $dates = app(InventoryService::class)->getDateRange(
                    $order->check_in_date->format('Y-m-d'),
                    $stayDays
                );
                app(InventoryService::class)->releaseInventoryForDates(
                    $order->room_type_id,
                    $dates,
                    $order->room_count
                );
            } catch (\Exception $e) {
                Log::warning('人工取消订单时释放库存失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            // 3. 通知携程平台（异步）
            try {
                \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
            } catch (\Exception $e) {
                Log::warning('通知携程订单取消失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => '取消订单成功',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status->value,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('人工取消订单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '取消订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 人工操作：拒绝取消订单
     */
    protected function rejectCancelManually(
        Order $order, 
        string $reason, 
        ?int $operatorId = null,
        ?ExceptionOrder $exceptionOrder = null
    ): array
    {
        try {
            DB::beginTransaction();

            // 1. 更新订单状态
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_REJECTED,
                '人工操作：拒绝取消订单 - ' . $reason,
                $operatorId
            );

            // 2. 标记异常订单为已处理
            if ($exceptionOrder) {
                $exceptionOrder->update([
                    'status' => ExceptionOrderStatus::RESOLVED,
                    'handler_id' => $operatorId,
                    'resolved_at' => now(),
                ]);
            }

            DB::commit();

            // 3. 通知携程平台（异步）
            try {
                \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
            } catch (\Exception $e) {
                Log::warning('通知携程订单取消拒绝失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => '拒绝取消订单成功',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status->value,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('人工拒绝取消订单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '拒绝取消订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 通知携程订单核销
     */
    protected function notifyCtripOrderConsumed(Order $order, array $data): void
    {
        // 只处理携程订单
        if ($order->otaPlatform->code->value !== 'ctrip') {
            return;
        }

        $useStartDate = $data['use_start_date'] ?? $order->check_in_date->format('Y-m-d');
        $useEndDate = $data['use_end_date'] ?? $order->check_out_date->format('Y-m-d');
        $useQuantity = $data['use_quantity'] ?? $order->room_count;
        $passengers = $data['passengers'] ?? [];
        $vouchers = $data['vouchers'] ?? [];

        $itemId = $order->ctrip_item_id ?: (string)$order->id;

        $this->ctripService->notifyOrderConsumed(
            $order->ota_order_no,
            $order->order_no,
            $itemId,
            $useStartDate,
            $useEndDate,
            $order->room_count,
            $useQuantity,
            $passengers,
            $vouchers
        );
    }
}

