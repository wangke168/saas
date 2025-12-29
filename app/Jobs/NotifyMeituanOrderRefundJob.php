<?php

namespace App\Jobs;

use App\Enums\ExceptionOrderStatus;
use App\Enums\ExceptionOrderType;
use App\Enums\OrderStatus;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Http\Client\MeituanClient;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Enums\OtaPlatform;
use App\Services\Resource\ResourceServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 通知美团订单退款结果
 * 异步通知，14分钟超时
 */
class NotifyMeituanOrderRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务超时时间（秒）
     */
    public $timeout = 840; // 14分钟

    /**
     * 创建任务实例
     */
    public function __construct(
        public Order $order,
        public string $reason // 退款原因
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        ResourceServiceFactory $factory
    ): void {
        $resourceService = $factory->getService($this->order);

        if (!$resourceService) {
            Log::warning('NotifyMeituanOrderRefundJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 1. 查询资源方是否可以取消
            $canCancelResult = $resourceService->canCancelOrder($this->order);

            if (!($canCancelResult['success'] ?? false)) {
                // 资源方表示不能取消，创建异常订单
                Log::warning('NotifyMeituanOrderRefundJob: 资源方表示订单不能取消', [
                    'order_id' => $this->order->id,
                    'reason' => $this->reason,
                    'resource_response' => $canCancelResult,
                ]);
                $this->createExceptionOrder($canCancelResult);
                // 通知美团退款失败
                $this->notifyMeituan($this->order, 506, $canCancelResult['message'] ?? '退款失败');
                return;
            }

            // 2. 如果可以取消，则调用资源方取消接口
            $cancelResult = $resourceService->cancelOrder($this->order, $this->reason);

            if ($cancelResult['success'] ?? false) {
                // 资源方取消成功
                $this->order->update([
                    'status' => OrderStatus::CANCEL_APPROVED,
                    'cancelled_at' => now(),
                ]);

                Log::info('NotifyMeituanOrderRefundJob: 资源方取消成功', [
                    'order_id' => $this->order->id,
                ]);

                // 通知美团退款成功
                $this->notifyMeituan($this->order, 200, '退款成功');
            } else {
                // 资源方取消失败 → 创建异常订单
                Log::warning('NotifyMeituanOrderRefundJob: 资源方取消失败', [
                    'order_id' => $this->order->id,
                    'reason' => $this->reason,
                    'resource_response' => $cancelResult,
                ]);
                $this->createExceptionOrder($cancelResult);
                // 通知美团退款失败
                $this->notifyMeituan($this->order, 506, $cancelResult['message'] ?? '退款失败');
            }
        } catch (\Exception $e) {
            // 超时或异常 → 创建异常订单
            Log::error('NotifyMeituanOrderRefundJob: 处理异常', [
                'order_id' => $this->order->id,
                'reason' => $this->reason,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);

            // 通知美团退款失败
            $this->notifyMeituan($this->order, 504, '接口超时');
        }
    }

    /**
     * 通知美团订单退款结果
     */
    protected function notifyMeituan(Order $order, int $code, string $describe): void
    {
        try {
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                Log::error('NotifyMeituanOrderRefundJob: 美团配置不存在');
                return;
            }

            $client = new MeituanClient($platform->config);

            $requestData = [
                'partnerId' => intval($platform->config->account),
                'body' => [
                    'orderId' => intval($order->ota_order_no),
                    'partnerOrderId' => $order->order_no,
                    'code' => $code,
                    'describe' => $describe,
                ],
            ];

            $result = $client->notifyOrderRefund($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('NotifyMeituanOrderRefundJob: 通知美团成功', [
                    'order_id' => $order->id,
                    'code' => $code,
                ]);
            } else {
                Log::error('NotifyMeituanOrderRefundJob: 通知美团失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyMeituanOrderRefundJob: 通知美团异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(array $result): void
    {
        $exceptionMessage = '资源方取消失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '资源方取消超时（10秒）';
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'cancel',
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);

        // 更新订单状态为申请取消中，等待人工处理
        $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);
    }
}

    /**
     * 任务超时时间（秒）
     */
    public $timeout = 840; // 14分钟

    /**
     * 创建任务实例
     */
    public function __construct(
        public Order $order,
        public string $reason // 退款原因
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        ResourceServiceFactory $factory
    ): void {
        $resourceService = $factory->getService($this->order);

        if (!$resourceService) {
            Log::warning('NotifyMeituanOrderRefundJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 1. 查询资源方是否可以取消
            $canCancelResult = $resourceService->canCancelOrder($this->order);

            if (!($canCancelResult['success'] ?? false)) {
                // 资源方表示不能取消，创建异常订单
                Log::warning('NotifyMeituanOrderRefundJob: 资源方表示订单不能取消', [
                    'order_id' => $this->order->id,
                    'reason' => $this->reason,
                    'resource_response' => $canCancelResult,
                ]);
                $this->createExceptionOrder($canCancelResult);
                // 通知美团退款失败
                $this->notifyMeituan($this->order, 506, $canCancelResult['message'] ?? '退款失败');
                return;
            }

            // 2. 如果可以取消，则调用资源方取消接口
            $cancelResult = $resourceService->cancelOrder($this->order, $this->reason);

            if ($cancelResult['success'] ?? false) {
                // 资源方取消成功
                $this->order->update([
                    'status' => OrderStatus::CANCEL_APPROVED,
                    'cancelled_at' => now(),
                ]);

                Log::info('NotifyMeituanOrderRefundJob: 资源方取消成功', [
                    'order_id' => $this->order->id,
                ]);

                // 通知美团退款成功
                $this->notifyMeituan($this->order, 200, '退款成功');
            } else {
                // 资源方取消失败 → 创建异常订单
                Log::warning('NotifyMeituanOrderRefundJob: 资源方取消失败', [
                    'order_id' => $this->order->id,
                    'reason' => $this->reason,
                    'resource_response' => $cancelResult,
                ]);
                $this->createExceptionOrder($cancelResult);
                // 通知美团退款失败
                $this->notifyMeituan($this->order, 506, $cancelResult['message'] ?? '退款失败');
            }
        } catch (\Exception $e) {
            // 超时或异常 → 创建异常订单
            Log::error('NotifyMeituanOrderRefundJob: 处理异常', [
                'order_id' => $this->order->id,
                'reason' => $this->reason,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);

            // 通知美团退款失败
            $this->notifyMeituan($this->order, 504, '接口超时');
        }
    }

    /**
     * 通知美团订单退款结果
     */
    protected function notifyMeituan(Order $order, int $code, string $describe): void
    {
        try {
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                Log::error('NotifyMeituanOrderRefundJob: 美团配置不存在');
                return;
            }

            $client = new MeituanClient($platform->config);

            $requestData = [
                'partnerId' => intval($platform->config->account),
                'body' => [
                    'orderId' => intval($order->ota_order_no),
                    'partnerOrderId' => $order->order_no,
                    'code' => $code,
                    'describe' => $describe,
                ],
            ];

            $result = $client->notifyOrderRefund($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('NotifyMeituanOrderRefundJob: 通知美团成功', [
                    'order_id' => $order->id,
                    'code' => $code,
                ]);
            } else {
                Log::error('NotifyMeituanOrderRefundJob: 通知美团失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyMeituanOrderRefundJob: 通知美团异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(array $result): void
    {
        $exceptionMessage = '资源方取消失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '资源方取消超时（10秒）';
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'cancel',
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);

        // 更新订单状态为申请取消中，等待人工处理
        $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);
    }
}

    /**
     * 任务超时时间（秒）
     */
    public $timeout = 840; // 14分钟

    /**
     * 创建任务实例
     */
    public function __construct(
        public Order $order,
        public string $reason // 退款原因
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        ResourceServiceFactory $factory
    ): void {
        $resourceService = $factory->getService($this->order);

        if (!$resourceService) {
            Log::warning('NotifyMeituanOrderRefundJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 1. 查询资源方是否可以取消
            $canCancelResult = $resourceService->canCancelOrder($this->order);

            if (!($canCancelResult['success'] ?? false)) {
                // 资源方表示不能取消，创建异常订单
                Log::warning('NotifyMeituanOrderRefundJob: 资源方表示订单不能取消', [
                    'order_id' => $this->order->id,
                    'reason' => $this->reason,
                    'resource_response' => $canCancelResult,
                ]);
                $this->createExceptionOrder($canCancelResult);
                // 通知美团退款失败
                $this->notifyMeituan($this->order, 506, $canCancelResult['message'] ?? '退款失败');
                return;
            }

            // 2. 如果可以取消，则调用资源方取消接口
            $cancelResult = $resourceService->cancelOrder($this->order, $this->reason);

            if ($cancelResult['success'] ?? false) {
                // 资源方取消成功
                $this->order->update([
                    'status' => OrderStatus::CANCEL_APPROVED,
                    'cancelled_at' => now(),
                ]);

                Log::info('NotifyMeituanOrderRefundJob: 资源方取消成功', [
                    'order_id' => $this->order->id,
                ]);

                // 通知美团退款成功
                $this->notifyMeituan($this->order, 200, '退款成功');
            } else {
                // 资源方取消失败 → 创建异常订单
                Log::warning('NotifyMeituanOrderRefundJob: 资源方取消失败', [
                    'order_id' => $this->order->id,
                    'reason' => $this->reason,
                    'resource_response' => $cancelResult,
                ]);
                $this->createExceptionOrder($cancelResult);
                // 通知美团退款失败
                $this->notifyMeituan($this->order, 506, $cancelResult['message'] ?? '退款失败');
            }
        } catch (\Exception $e) {
            // 超时或异常 → 创建异常订单
            Log::error('NotifyMeituanOrderRefundJob: 处理异常', [
                'order_id' => $this->order->id,
                'reason' => $this->reason,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);

            // 通知美团退款失败
            $this->notifyMeituan($this->order, 504, '接口超时');
        }
    }

    /**
     * 通知美团订单退款结果
     */
    protected function notifyMeituan(Order $order, int $code, string $describe): void
    {
        try {
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                Log::error('NotifyMeituanOrderRefundJob: 美团配置不存在');
                return;
            }

            $client = new MeituanClient($platform->config);

            $requestData = [
                'partnerId' => intval($platform->config->account),
                'body' => [
                    'orderId' => intval($order->ota_order_no),
                    'partnerOrderId' => $order->order_no,
                    'code' => $code,
                    'describe' => $describe,
                ],
            ];

            $result = $client->notifyOrderRefund($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('NotifyMeituanOrderRefundJob: 通知美团成功', [
                    'order_id' => $order->id,
                    'code' => $code,
                ]);
            } else {
                Log::error('NotifyMeituanOrderRefundJob: 通知美团失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyMeituanOrderRefundJob: 通知美团异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(array $result): void
    {
        $exceptionMessage = '资源方取消失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '资源方取消超时（10秒）';
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'cancel',
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);

        // 更新订单状态为申请取消中，等待人工处理
        $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);
    }
}

    /**
     * 任务超时时间（秒）
     */
    public $timeout = 840; // 14分钟

    /**
     * 创建任务实例
     */
    public function __construct(
        public Order $order,
        public string $reason // 退款原因
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        ResourceServiceFactory $factory
    ): void {
        $resourceService = $factory->getService($this->order);

        if (!$resourceService) {
            Log::warning('NotifyMeituanOrderRefundJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 1. 查询资源方是否可以取消
            $canCancelResult = $resourceService->canCancelOrder($this->order);

            if (!($canCancelResult['success'] ?? false)) {
                // 资源方表示不能取消，创建异常订单
                Log::warning('NotifyMeituanOrderRefundJob: 资源方表示订单不能取消', [
                    'order_id' => $this->order->id,
                    'reason' => $this->reason,
                    'resource_response' => $canCancelResult,
                ]);
                $this->createExceptionOrder($canCancelResult);
                // 通知美团退款失败
                $this->notifyMeituan($this->order, 506, $canCancelResult['message'] ?? '退款失败');
                return;
            }

            // 2. 如果可以取消，则调用资源方取消接口
            $cancelResult = $resourceService->cancelOrder($this->order, $this->reason);

            if ($cancelResult['success'] ?? false) {
                // 资源方取消成功
                $this->order->update([
                    'status' => OrderStatus::CANCEL_APPROVED,
                    'cancelled_at' => now(),
                ]);

                Log::info('NotifyMeituanOrderRefundJob: 资源方取消成功', [
                    'order_id' => $this->order->id,
                ]);

                // 通知美团退款成功
                $this->notifyMeituan($this->order, 200, '退款成功');
            } else {
                // 资源方取消失败 → 创建异常订单
                Log::warning('NotifyMeituanOrderRefundJob: 资源方取消失败', [
                    'order_id' => $this->order->id,
                    'reason' => $this->reason,
                    'resource_response' => $cancelResult,
                ]);
                $this->createExceptionOrder($cancelResult);
                // 通知美团退款失败
                $this->notifyMeituan($this->order, 506, $cancelResult['message'] ?? '退款失败');
            }
        } catch (\Exception $e) {
            // 超时或异常 → 创建异常订单
            Log::error('NotifyMeituanOrderRefundJob: 处理异常', [
                'order_id' => $this->order->id,
                'reason' => $this->reason,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);

            // 通知美团退款失败
            $this->notifyMeituan($this->order, 504, '接口超时');
        }
    }

    /**
     * 通知美团订单退款结果
     */
    protected function notifyMeituan(Order $order, int $code, string $describe): void
    {
        try {
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                Log::error('NotifyMeituanOrderRefundJob: 美团配置不存在');
                return;
            }

            $client = new MeituanClient($platform->config);

            $requestData = [
                'partnerId' => intval($platform->config->account),
                'body' => [
                    'orderId' => intval($order->ota_order_no),
                    'partnerOrderId' => $order->order_no,
                    'code' => $code,
                    'describe' => $describe,
                ],
            ];

            $result = $client->notifyOrderRefund($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('NotifyMeituanOrderRefundJob: 通知美团成功', [
                    'order_id' => $order->id,
                    'code' => $code,
                ]);
            } else {
                Log::error('NotifyMeituanOrderRefundJob: 通知美团失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyMeituanOrderRefundJob: 通知美团异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(array $result): void
    {
        $exceptionMessage = '资源方取消失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '资源方取消超时（10秒）';
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'cancel',
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);

        // 更新订单状态为申请取消中，等待人工处理
        $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);
    }
}