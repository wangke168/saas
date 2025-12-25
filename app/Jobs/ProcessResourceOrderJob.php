<?php

namespace App\Jobs;

use App\Enums\ExceptionOrderStatus;
use App\Enums\ExceptionOrderType;
use App\Enums\OrderStatus;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\Resource\ResourceServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 处理资源方订单操作（接单/取消）
 * 异步调用景区方接口，设置 10 秒超时
 */
class ProcessResourceOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务超时时间（秒）
     */
    public $timeout = 10;

    /**
     * 创建任务实例
     */
    public function __construct(
        public Order $order,
        public string $operation // 'confirm' 或 'cancel'
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        ResourceServiceFactory $factory,
        InventoryService $inventoryService
    ): void {
        $resourceService = $factory->getService($this->order);

        if (!$resourceService) {
            Log::warning('ProcessResourceOrderJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
                'operation' => $this->operation,
            ]);
            return;
        }

        try {
            if ($this->operation === 'confirm') {
                $result = $resourceService->confirmOrder($this->order);
                $this->handleConfirmResult($result, $inventoryService);
            } else {
                $reason = '携程申请取消订单';
                $result = $resourceService->cancelOrder($this->order, $reason);
                $this->handleCancelResult($result, $inventoryService);
            }
        } catch (\Exception $e) {
            // 超时或异常 → 创建异常订单
            Log::error('ProcessResourceOrderJob: 处理异常', [
                'order_id' => $this->order->id,
                'operation' => $this->operation,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);
        }
    }

    /**
     * 处理接单结果
     */
    protected function handleConfirmResult(array $result, InventoryService $inventoryService): void
    {
        if ($result['success'] ?? false) {
            // 景区方成功
            $this->order->update([
                'status' => OrderStatus::CONFIRMED,
                'resource_order_no' => $result['data']['resource_order_no'] ?? null,
                'confirmed_at' => now(),
            ]);

            Log::info('ProcessResourceOrderJob: 景区方接单成功', [
                'order_id' => $this->order->id,
                'resource_order_no' => $result['data']['resource_order_no'] ?? null,
            ]);

            // 通知携程订单确认
            \App\Jobs\NotifyOtaOrderStatusJob::dispatch($this->order);
        } else {
            // 景区方失败 → 创建异常订单
            Log::warning('ProcessResourceOrderJob: 景区方接单失败', [
                'order_id' => $this->order->id,
                'error' => $result['message'] ?? '未知错误',
            ]);

            $this->createExceptionOrder($result);
        }
    }

    /**
     * 处理取消结果
     */
    protected function handleCancelResult(array $result, InventoryService $inventoryService): void
    {
        if ($result['success'] ?? false) {
            // 景区方成功
            $this->order->update([
                'status' => OrderStatus::CANCEL_APPROVED,
                'cancelled_at' => now(),
            ]);

            // 释放库存
            try {
                $stayDays = $this->order->product->stay_days ?? 1;
                $dates = $inventoryService->getDateRange(
                    $this->order->check_in_date->format('Y-m-d'),
                    $stayDays
                );
                $inventoryService->releaseInventoryForDates(
                    $this->order->room_type_id,
                    $dates,
                    $this->order->room_count
                );
            } catch (\Exception $e) {
                Log::warning('ProcessResourceOrderJob: 释放库存失败', [
                    'order_id' => $this->order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('ProcessResourceOrderJob: 景区方取消成功', [
                'order_id' => $this->order->id,
            ]);

            // 通知携程取消成功
            \App\Jobs\NotifyOtaOrderStatusJob::dispatch($this->order);
        } else {
            // 景区方失败 → 创建异常订单
            $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

            Log::warning('ProcessResourceOrderJob: 景区方取消失败', [
                'order_id' => $this->order->id,
                'error' => $result['message'] ?? '未知错误',
            ]);

            $this->createExceptionOrder($result);
        }
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(array $result): void
    {
        $exceptionMessage = $this->operation === 'confirm'
            ? '景区方接单失败：' . ($result['message'] ?? '未知错误')
            : '景区方取消失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = $this->operation === 'confirm'
                ? '景区方接单超时（10秒）'
                : '景区方取消超时（10秒）';
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => $this->operation,
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);

        // 如果接单失败，保持状态为 CONFIRMING
        // 如果取消失败，状态已在 handleCancelResult 中更新为 CANCEL_REQUESTED
        if ($this->operation === 'confirm') {
            $this->order->update(['status' => OrderStatus::CONFIRMING]);
        }
    }
}

