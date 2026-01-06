<?php

namespace App\Services;

use App\Enums\ExceptionOrderStatus;
use App\Enums\OtaPlatform;
use App\Enums\OrderStatus;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Services\OTA\CtripService;
use App\Services\OTA\NotificationFactory;
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

        $resourceService = ResourceServiceFactory::getService($order, 'order');

        if ($exceptionOrder) {
            // 异常订单处理：直接走人工流程，不再调用资源方接口，直接对接OTA平台
            return $this->confirmOrderManually($order, $remark ?? '异常订单人工处理：接单', $operatorId, $exceptionOrder);
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
     * 
     * @deprecated 异常订单不应该调用此方法，应该使用 confirmOrderManually()
     */
    protected function confirmOrderWithResource(
        Order $order, 
        ResourceServiceInterface $resourceService, 
        ?string $remark,
        ?int $operatorId = null,
        ?ExceptionOrder $exceptionOrder = null
    ): array
    {
        // 如果订单有异常订单，不允许调用资源方接口
        $pendingExceptionOrder = ExceptionOrder::where('order_id', $order->id)
            ->where('status', ExceptionOrderStatus::PENDING)
            ->where('exception_data->operation', 'confirm')
            ->first();
        
        if ($pendingExceptionOrder) {
            Log::error('OrderOperationService::confirmOrderWithResource: 异常订单不允许调用资源方接口', [
                'order_id' => $order->id,
                'exception_order_id' => $pendingExceptionOrder->id,
            ]);
            
            return [
                'success' => false,
                'message' => '异常订单不允许调用资源方接口，请使用人工操作',
            ];
        }

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

            // 5. 通知OTA平台
            // 重新加载订单关联数据，确保 otaPlatform 已加载
            $order->load(['otaPlatform']);
            
            // 如果是美团订单，同步通知以确保及时性（美团对响应时间要求较高）
            // 其他平台使用异步通知
            if ($order->otaPlatform?->code === OtaPlatform::MEITUAN) {
                Log::info('OrderOperationService::confirmOrderWithResource: 美团订单，同步通知', [
                    'order_id' => $order->id,
                ]);
                
                try {
                    $notification = NotificationFactory::create($order);
                    if ($notification) {
                        $notification->notifyOrderConfirmed($order);
                        Log::info('OrderOperationService::confirmOrderWithResource: 美团订单同步通知成功', [
                            'order_id' => $order->id,
                        ]);
                    } else {
                        Log::warning('OrderOperationService::confirmOrderWithResource: 无法创建美团通知服务', [
                            'order_id' => $order->id,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('OrderOperationService::confirmOrderWithResource: 美团订单同步通知失败', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // 同步通知失败不影响主流程，继续派发异步Job作为备份
                    \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order)
                        ->onQueue('ota-notification');
                }
            } else {
                // 其他平台使用异步通知
                try {
                    \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order)
                        ->onQueue('ota-notification');
                } catch (\Exception $e) {
                    Log::warning('通知OTA订单确认失败', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
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
     * 
     * @param Order $order 订单
     * @param string|null $remark 备注
     * @param int|null $operatorId 操作人ID
     * @param ExceptionOrder|null $exceptionOrder 异常订单（如果存在）
     */
    protected function confirmOrderManually(Order $order, ?string $remark, ?int $operatorId, ?ExceptionOrder $exceptionOrder = null): array
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

            // 2. 标记异常订单为已处理（如果存在）
            if ($exceptionOrder) {
                $exceptionOrder->update([
                    'status' => ExceptionOrderStatus::RESOLVED,
                    'handler_id' => $operatorId,
                    'resolved_at' => now(),
                ]);
            }

            DB::commit();

            // 3. 通知OTA平台
            // 重新加载订单关联数据，确保 otaPlatform 已加载
            $order->load(['otaPlatform']);
            
            // 如果是美团订单，同步通知以确保及时性（美团对响应时间要求较高）
            // 其他平台使用异步通知
            if ($order->otaPlatform?->code === OtaPlatform::MEITUAN) {
                Log::info('OrderOperationService::confirmOrderManually: 美团订单，同步通知', [
                    'order_id' => $order->id,
                ]);
                
                try {
                    $notification = NotificationFactory::create($order);
                    if ($notification) {
                        $notification->notifyOrderConfirmed($order);
                        Log::info('OrderOperationService::confirmOrderManually: 美团订单同步通知成功', [
                            'order_id' => $order->id,
                        ]);
                    } else {
                        Log::warning('OrderOperationService::confirmOrderManually: 无法创建美团通知服务', [
                            'order_id' => $order->id,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('OrderOperationService::confirmOrderManually: 美团订单同步通知失败', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // 同步通知失败不影响主流程，继续派发异步Job作为备份
                    \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order)
                        ->onQueue('ota-notification');
                }
            } else {
                // 其他平台使用异步通知
                try {
                    Log::info('准备派发 NotifyOtaOrderStatusJob', [
                        'order_id' => $order->id,
                        'order_status' => $order->status->value,
                        'queue_connection' => config('queue.default'),
                    ]);
                    
                    \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order)
                        ->onQueue('ota-notification');
                    
                    Log::info('NotifyOtaOrderStatusJob 派发成功', [
                        'order_id' => $order->id,
                    ]);
                } catch (\Exception $e) {
                    Log::error('通知OTA订单确认失败', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
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
        $resourceService = ResourceServiceFactory::getService($order, 'order');

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
        $resourceService = ResourceServiceFactory::getService($order, 'order');

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

            // 3. 通知OTA平台（调用核销通知接口）
            try {
                $this->notifyOtaOrderConsumed($order, $data);
            } catch (\Exception $e) {
                Log::warning('通知OTA订单核销失败', [
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

            // 2. 通知OTA平台（调用核销通知接口）
            try {
                $this->notifyOtaOrderConsumed($order, $data);
            } catch (\Exception $e) {
                Log::warning('通知OTA订单核销失败', [
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

        $resourceService = ResourceServiceFactory::getService($order, 'order');

        if ($exceptionOrder) {
            // 异常订单处理：直接走人工流程，不再调用资源方接口，直接对接OTA平台
            if ($approve) {
                return $this->cancelOrderManually($order, $reason, $operatorId, $exceptionOrder);
            } else {
                return $this->rejectCancelManually($order, $reason, $operatorId, $exceptionOrder);
            }
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
     * 
     * @deprecated 异常订单不应该调用此方法，应该使用 cancelOrderManually()
     */
    protected function cancelOrderWithResource(
        Order $order, 
        ResourceServiceInterface $resourceService, 
        string $reason,
        ?int $operatorId = null,
        ?ExceptionOrder $exceptionOrder = null
    ): array
    {
        // 如果订单有异常订单，不允许调用资源方接口
        $pendingExceptionOrder = ExceptionOrder::where('order_id', $order->id)
            ->where('status', ExceptionOrderStatus::PENDING)
            ->where('exception_data->operation', 'cancel')
            ->first();
        
        if ($pendingExceptionOrder) {
            Log::error('OrderOperationService::cancelOrderWithResource: 异常订单不允许调用资源方接口', [
                'order_id' => $order->id,
                'exception_order_id' => $pendingExceptionOrder->id,
            ]);
            
            return [
                'success' => false,
                'message' => '异常订单不允许调用资源方接口，请使用人工操作',
            ];
        }

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
     * 
     * @param Order $order 订单
     * @param string $reason 取消原因
     * @param int|null $operatorId 操作人ID
     * @param ExceptionOrder|null $exceptionOrder 异常订单（如果存在）
     */
    protected function cancelOrderManually(Order $order, string $reason, ?int $operatorId = null, ?ExceptionOrder $exceptionOrder = null): array
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

            // 3. 标记异常订单为已处理（如果存在）
            if ($exceptionOrder) {
                $exceptionOrder->update([
                    'status' => ExceptionOrderStatus::RESOLVED,
                    'handler_id' => $operatorId,
                    'resolved_at' => now(),
                ]);
            }

            DB::commit();

            // 4. 通知OTA平台（异步）
            try {
                Log::info('准备派发 NotifyOtaOrderStatusJob（取消订单）', [
                    'order_id' => $order->id,
                    'order_status' => $order->status->value,
                    'queue_connection' => config('queue.default'),
                ]);
                
                \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
                
                Log::info('NotifyOtaOrderStatusJob 派发成功（取消订单）', [
                    'order_id' => $order->id,
                ]);
            } catch (\Exception $e) {
                Log::error('通知OTA订单取消失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
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
     * 通知OTA平台订单核销
     */
    protected function notifyOtaOrderConsumed(Order $order, array $data): void
    {
        $platform = $order->otaPlatform;
        if (!$platform) {
            return;
        }

        if ($platform->code->value === \App\Enums\OtaPlatform::CTRIP->value) {
            // 携程订单
            $this->notifyCtripOrderConsumed($order, $data);
        } elseif ($platform->code->value === \App\Enums\OtaPlatform::MEITUAN->value) {
            // 美团订单
            $this->notifyMeituanOrderConsumed($order, $data);
        }
    }

    /**
     * 通知携程订单核销
     */
    protected function notifyCtripOrderConsumed(Order $order, array $data): void
    {
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

    /**
     * 通知美团订单消费（核销）
     */
    protected function notifyMeituanOrderConsumed(Order $order, array $data): void
    {
        try {
            $platform = $order->otaPlatform;
            if (!$platform || !$platform->config) {
                Log::error('NotifyMeituanOrderConsumed: 美团配置不存在');
                return;
            }

            $client = new \App\Http\Client\MeituanClient($platform->config);

            $useStartDate = $data['use_start_date'] ?? $order->check_in_date->format('Y-m-d');
            $useEndDate = $data['use_end_date'] ?? $order->check_out_date->format('Y-m-d');
            $useQuantity = $data['use_quantity'] ?? $order->room_count;

            $requestData = [
                'partnerId' => intval($platform->config->account),
                'body' => [
                    'orderId' => intval($order->ota_order_no),
                    'partnerOrderId' => $order->order_no,
                    'useStartDate' => $useStartDate,
                    'useEndDate' => $useEndDate,
                    'quantity' => $order->room_count,
                    'usedQuantity' => $useQuantity,
                ],
            ];

            // 如果是实名制订单，传credentialList
            if ($order->real_name_type === 1 && !empty($order->credential_list)) {
                $requestData['body']['credentialList'] = [];
                foreach ($order->credential_list as $credential) {
                    $requestData['body']['credentialList'][] = [
                        'credentialType' => $credential['credentialType'] ?? 0,
                        'credentialNo' => $credential['credentialNo'] ?? '',
                        'voucher' => $credential['voucher'] ?? '',
                    ];
                }
            }

            $result = $client->notifyOrderConsume($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('通知美团订单消费成功', [
                    'order_id' => $order->id,
                ]);
            } else {
                Log::error('通知美团订单消费失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('通知美团订单消费异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
