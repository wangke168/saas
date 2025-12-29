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
 * 处理资源方订单取消（查询是否可以取消，然后决定是否调用取消接口）
 * 异步调用景区方接口，设置 10 秒超时
 */
class ProcessResourceCancelOrderJob implements ShouldQueue
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
        public string $reason // 取消原因
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        InventoryService $inventoryService
    ): void {
        $resourceService = ResourceServiceFactory::getService($this->order, 'order');

        if (!$resourceService) {
            Log::warning('ProcessResourceCancelOrderJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            
            // 无法获取资源方服务，创建异常订单，等待人工处理
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 1. 先查询是否可以取消
            $canCancelResult = $resourceService->canCancelOrder($this->order);

            if (!($canCancelResult['can_cancel'] ?? false)) {
                // 不可以取消，创建异常订单
                Log::warning('ProcessResourceCancelOrderJob: 景区方返回不可以取消', [
                    'order_id' => $this->order->id,
                    'message' => $canCancelResult['message'] ?? '未知原因',
                ]);

                $this->createExceptionOrder([
                    'success' => false,
                    'message' => $canCancelResult['message'] ?? '景区方返回：不可以取消',
                    'can_cancel' => false,
                    'query_result' => $canCancelResult,
                ]);

                // 更新订单状态为取消申请中
                $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

                return;
            }

            // 2. 可以取消，直接调用取消接口
            $cancelResult = $resourceService->cancelOrder($this->order, $this->reason);

            if ($cancelResult['success'] ?? false) {
                // 取消成功
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
                    Log::warning('ProcessResourceCancelOrderJob: 释放库存失败', [
                        'order_id' => $this->order->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::info('ProcessResourceCancelOrderJob: 景区方取消成功', [
                    'order_id' => $this->order->id,
                ]);

                // 刷新订单对象，确保状态是最新的
                $this->order->refresh();

                // 根据OTA平台类型通知取消成功
                $this->notifyOtaOrderCancelled($this->order);
            } else {
                // 取消失败（虽然查询说可以取消，但实际取消时失败）
                // 创建异常订单
                $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

                Log::warning('ProcessResourceCancelOrderJob: 景区方取消失败', [
                    'order_id' => $this->order->id,
                    'error' => $cancelResult['message'] ?? '未知错误',
                ]);

                $this->createExceptionOrder([
                    'success' => false,
                    'message' => $cancelResult['message'] ?? '景区方取消失败',
                    'can_cancel' => true, // 查询时返回可以取消
                    'cancel_result' => $cancelResult,
                ]);
            }
        } catch (\Exception $e) {
            // 查询或取消接口超时/异常 → 创建异常订单
            Log::error('ProcessResourceCancelOrderJob: 处理异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);
        }
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(array $result): void
    {
        $exceptionMessage = '景区方取消订单处理失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '景区方查询是否可以取消超时（10秒）';
        } elseif (isset($result['can_cancel']) && !$result['can_cancel']) {
            $exceptionMessage = '景区方返回：不可以取消 - ' . ($result['message'] ?? '未知原因');
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ($result['timeout'] ?? false) ? ExceptionOrderType::TIMEOUT : ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'cancel',
                'reason' => $this->reason,
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
                'can_cancel' => $result['can_cancel'] ?? null,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);
    }

    /**
     * 通知OTA平台订单取消
     */
    protected function notifyOtaOrderCancelled(Order $order): void
    {
        $platform = $order->otaPlatform;
        if (!$platform) {
            return;
        }

        if ($platform->code->value === \App\Enums\OtaPlatform::CTRIP->value) {
            // 携程：使用NotifyOtaOrderStatusJob
            \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
        } elseif ($platform->code->value === \App\Enums\OtaPlatform::MEITUAN->value) {
            // 美团：使用NotifyMeituanOrderRefundJob
            \App\Jobs\NotifyMeituanOrderRefundJob::dispatch($order, '资源方取消成功');
        }
    }
}


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
 * 处理资源方订单取消（查询是否可以取消，然后决定是否调用取消接口）
 * 异步调用景区方接口，设置 10 秒超时
 */
class ProcessResourceCancelOrderJob implements ShouldQueue
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
        public string $reason // 取消原因
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        InventoryService $inventoryService
    ): void {
        $resourceService = ResourceServiceFactory::getService($this->order, 'order');

        if (!$resourceService) {
            Log::warning('ProcessResourceCancelOrderJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            
            // 无法获取资源方服务，创建异常订单，等待人工处理
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 1. 先查询是否可以取消
            $canCancelResult = $resourceService->canCancelOrder($this->order);

            if (!($canCancelResult['can_cancel'] ?? false)) {
                // 不可以取消，创建异常订单
                Log::warning('ProcessResourceCancelOrderJob: 景区方返回不可以取消', [
                    'order_id' => $this->order->id,
                    'message' => $canCancelResult['message'] ?? '未知原因',
                ]);

                $this->createExceptionOrder([
                    'success' => false,
                    'message' => $canCancelResult['message'] ?? '景区方返回：不可以取消',
                    'can_cancel' => false,
                    'query_result' => $canCancelResult,
                ]);

                // 更新订单状态为取消申请中
                $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

                return;
            }

            // 2. 可以取消，直接调用取消接口
            $cancelResult = $resourceService->cancelOrder($this->order, $this->reason);

            if ($cancelResult['success'] ?? false) {
                // 取消成功
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
                    Log::warning('ProcessResourceCancelOrderJob: 释放库存失败', [
                        'order_id' => $this->order->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::info('ProcessResourceCancelOrderJob: 景区方取消成功', [
                    'order_id' => $this->order->id,
                ]);

                // 刷新订单对象，确保状态是最新的
                $this->order->refresh();

                // 根据OTA平台类型通知取消成功
                $this->notifyOtaOrderCancelled($this->order);
            } else {
                // 取消失败（虽然查询说可以取消，但实际取消时失败）
                // 创建异常订单
                $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

                Log::warning('ProcessResourceCancelOrderJob: 景区方取消失败', [
                    'order_id' => $this->order->id,
                    'error' => $cancelResult['message'] ?? '未知错误',
                ]);

                $this->createExceptionOrder([
                    'success' => false,
                    'message' => $cancelResult['message'] ?? '景区方取消失败',
                    'can_cancel' => true, // 查询时返回可以取消
                    'cancel_result' => $cancelResult,
                ]);
            }
        } catch (\Exception $e) {
            // 查询或取消接口超时/异常 → 创建异常订单
            Log::error('ProcessResourceCancelOrderJob: 处理异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);
        }
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(array $result): void
    {
        $exceptionMessage = '景区方取消订单处理失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '景区方查询是否可以取消超时（10秒）';
        } elseif (isset($result['can_cancel']) && !$result['can_cancel']) {
            $exceptionMessage = '景区方返回：不可以取消 - ' . ($result['message'] ?? '未知原因');
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ($result['timeout'] ?? false) ? ExceptionOrderType::TIMEOUT : ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'cancel',
                'reason' => $this->reason,
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
                'can_cancel' => $result['can_cancel'] ?? null,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);
    }

    /**
     * 通知OTA平台订单取消
     */
    protected function notifyOtaOrderCancelled(Order $order): void
    {
        $platform = $order->otaPlatform;
        if (!$platform) {
            return;
        }

        if ($platform->code->value === \App\Enums\OtaPlatform::CTRIP->value) {
            // 携程：使用NotifyOtaOrderStatusJob
            \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
        } elseif ($platform->code->value === \App\Enums\OtaPlatform::MEITUAN->value) {
            // 美团：使用NotifyMeituanOrderRefundJob
            \App\Jobs\NotifyMeituanOrderRefundJob::dispatch($order, '资源方取消成功');
        }
    }
}


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
 * 处理资源方订单取消（查询是否可以取消，然后决定是否调用取消接口）
 * 异步调用景区方接口，设置 10 秒超时
 */
class ProcessResourceCancelOrderJob implements ShouldQueue
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
        public string $reason // 取消原因
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        InventoryService $inventoryService
    ): void {
        $resourceService = ResourceServiceFactory::getService($this->order, 'order');

        if (!$resourceService) {
            Log::warning('ProcessResourceCancelOrderJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            
            // 无法获取资源方服务，创建异常订单，等待人工处理
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 1. 先查询是否可以取消
            $canCancelResult = $resourceService->canCancelOrder($this->order);

            if (!($canCancelResult['can_cancel'] ?? false)) {
                // 不可以取消，创建异常订单
                Log::warning('ProcessResourceCancelOrderJob: 景区方返回不可以取消', [
                    'order_id' => $this->order->id,
                    'message' => $canCancelResult['message'] ?? '未知原因',
                ]);

                $this->createExceptionOrder([
                    'success' => false,
                    'message' => $canCancelResult['message'] ?? '景区方返回：不可以取消',
                    'can_cancel' => false,
                    'query_result' => $canCancelResult,
                ]);

                // 更新订单状态为取消申请中
                $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

                return;
            }

            // 2. 可以取消，直接调用取消接口
            $cancelResult = $resourceService->cancelOrder($this->order, $this->reason);

            if ($cancelResult['success'] ?? false) {
                // 取消成功
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
                    Log::warning('ProcessResourceCancelOrderJob: 释放库存失败', [
                        'order_id' => $this->order->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::info('ProcessResourceCancelOrderJob: 景区方取消成功', [
                    'order_id' => $this->order->id,
                ]);

                // 刷新订单对象，确保状态是最新的
                $this->order->refresh();

                // 根据OTA平台类型通知取消成功
                $this->notifyOtaOrderCancelled($this->order);
            } else {
                // 取消失败（虽然查询说可以取消，但实际取消时失败）
                // 创建异常订单
                $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

                Log::warning('ProcessResourceCancelOrderJob: 景区方取消失败', [
                    'order_id' => $this->order->id,
                    'error' => $cancelResult['message'] ?? '未知错误',
                ]);

                $this->createExceptionOrder([
                    'success' => false,
                    'message' => $cancelResult['message'] ?? '景区方取消失败',
                    'can_cancel' => true, // 查询时返回可以取消
                    'cancel_result' => $cancelResult,
                ]);
            }
        } catch (\Exception $e) {
            // 查询或取消接口超时/异常 → 创建异常订单
            Log::error('ProcessResourceCancelOrderJob: 处理异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);
        }
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(array $result): void
    {
        $exceptionMessage = '景区方取消订单处理失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '景区方查询是否可以取消超时（10秒）';
        } elseif (isset($result['can_cancel']) && !$result['can_cancel']) {
            $exceptionMessage = '景区方返回：不可以取消 - ' . ($result['message'] ?? '未知原因');
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ($result['timeout'] ?? false) ? ExceptionOrderType::TIMEOUT : ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'cancel',
                'reason' => $this->reason,
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
                'can_cancel' => $result['can_cancel'] ?? null,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);
    }

    /**
     * 通知OTA平台订单取消
     */
    protected function notifyOtaOrderCancelled(Order $order): void
    {
        $platform = $order->otaPlatform;
        if (!$platform) {
            return;
        }

        if ($platform->code->value === \App\Enums\OtaPlatform::CTRIP->value) {
            // 携程：使用NotifyOtaOrderStatusJob
            \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
        } elseif ($platform->code->value === \App\Enums\OtaPlatform::MEITUAN->value) {
            // 美团：使用NotifyMeituanOrderRefundJob
            \App\Jobs\NotifyMeituanOrderRefundJob::dispatch($order, '资源方取消成功');
        }
    }
}


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
 * 处理资源方订单取消（查询是否可以取消，然后决定是否调用取消接口）
 * 异步调用景区方接口，设置 10 秒超时
 */
class ProcessResourceCancelOrderJob implements ShouldQueue
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
        public string $reason // 取消原因
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        InventoryService $inventoryService
    ): void {
        $resourceService = ResourceServiceFactory::getService($this->order, 'order');

        if (!$resourceService) {
            Log::warning('ProcessResourceCancelOrderJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            
            // 无法获取资源方服务，创建异常订单，等待人工处理
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 1. 先查询是否可以取消
            $canCancelResult = $resourceService->canCancelOrder($this->order);

            if (!($canCancelResult['can_cancel'] ?? false)) {
                // 不可以取消，创建异常订单
                Log::warning('ProcessResourceCancelOrderJob: 景区方返回不可以取消', [
                    'order_id' => $this->order->id,
                    'message' => $canCancelResult['message'] ?? '未知原因',
                ]);

                $this->createExceptionOrder([
                    'success' => false,
                    'message' => $canCancelResult['message'] ?? '景区方返回：不可以取消',
                    'can_cancel' => false,
                    'query_result' => $canCancelResult,
                ]);

                // 更新订单状态为取消申请中
                $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

                return;
            }

            // 2. 可以取消，直接调用取消接口
            $cancelResult = $resourceService->cancelOrder($this->order, $this->reason);

            if ($cancelResult['success'] ?? false) {
                // 取消成功
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
                    Log::warning('ProcessResourceCancelOrderJob: 释放库存失败', [
                        'order_id' => $this->order->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::info('ProcessResourceCancelOrderJob: 景区方取消成功', [
                    'order_id' => $this->order->id,
                ]);

                // 刷新订单对象，确保状态是最新的
                $this->order->refresh();

                // 根据OTA平台类型通知取消成功
                $this->notifyOtaOrderCancelled($this->order);
            } else {
                // 取消失败（虽然查询说可以取消，但实际取消时失败）
                // 创建异常订单
                $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

                Log::warning('ProcessResourceCancelOrderJob: 景区方取消失败', [
                    'order_id' => $this->order->id,
                    'error' => $cancelResult['message'] ?? '未知错误',
                ]);

                $this->createExceptionOrder([
                    'success' => false,
                    'message' => $cancelResult['message'] ?? '景区方取消失败',
                    'can_cancel' => true, // 查询时返回可以取消
                    'cancel_result' => $cancelResult,
                ]);
            }
        } catch (\Exception $e) {
            // 查询或取消接口超时/异常 → 创建异常订单
            Log::error('ProcessResourceCancelOrderJob: 处理异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);
        }
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(array $result): void
    {
        $exceptionMessage = '景区方取消订单处理失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '景区方查询是否可以取消超时（10秒）';
        } elseif (isset($result['can_cancel']) && !$result['can_cancel']) {
            $exceptionMessage = '景区方返回：不可以取消 - ' . ($result['message'] ?? '未知原因');
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ($result['timeout'] ?? false) ? ExceptionOrderType::TIMEOUT : ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'cancel',
                'reason' => $this->reason,
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
                'can_cancel' => $result['can_cancel'] ?? null,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);
    }

    /**
     * 通知OTA平台订单取消
     */
    protected function notifyOtaOrderCancelled(Order $order): void
    {
        $platform = $order->otaPlatform;
        if (!$platform) {
            return;
        }

        if ($platform->code->value === \App\Enums\OtaPlatform::CTRIP->value) {
            // 携程：使用NotifyOtaOrderStatusJob
            \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
        } elseif ($platform->code->value === \App\Enums\OtaPlatform::MEITUAN->value) {
            // 美团：使用NotifyMeituanOrderRefundJob
            \App\Jobs\NotifyMeituanOrderRefundJob::dispatch($order, '资源方取消成功');
        }
    }
}
