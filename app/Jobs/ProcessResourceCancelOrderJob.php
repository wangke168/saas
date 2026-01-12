<?php

namespace App\Jobs;

use App\Enums\ExceptionOrderStatus;
use App\Enums\ExceptionOrderType;
use App\Enums\OrderStatus;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OrderService;
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
     * 任务最大尝试次数（包括首次尝试）
     */
    public int $tries = 4; // 首次 + 3次重试 = 总共4次尝试

    /**
     * 计算重试延迟时间（指数退避）
     */
    public function backoff(): array
    {
        return [
            2,  // 第1次重试：2秒
            5,  // 第2次重试：5秒
            10, // 第3次重试：10秒
        ];
    }

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
        Log::info('ProcessResourceCancelOrderJob 开始执行', [
            'order_id' => $this->order->id,
            'reason' => $this->reason,
            'order_status' => $this->order->status->value,
            'ota_order_no' => $this->order->ota_order_no,
            'attempt' => $this->attempts(), // 当前尝试次数
        ]);

        $resourceService = ResourceServiceFactory::getService($this->order, 'order');

        if (!$resourceService) {
            Log::warning('ProcessResourceCancelOrderJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            
            // 无法获取资源方服务，不重试，直接创建异常订单
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务，请检查景区配置（资源配置、软件服务商、同步模式）',
            ]);
            return;
        }

        try {
            // 1. 先查询是否可以取消
            $canCancelResult = $resourceService->canCancelOrder($this->order);

            if (!($canCancelResult['can_cancel'] ?? false)) {
                // 不可以取消，创建异常订单（业务性错误，不重试）
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
                Log::info('ProcessResourceCancelOrderJob: 景区方取消成功，准备通知OTA平台', [
                    'order_id' => $this->order->id,
                ]);

                // 先通知OTA平台，等待成功后再更新订单状态
                // 重新加载订单关联数据，确保 otaPlatform 已加载
                $this->order->load(['otaPlatform']);
                
                if (!$this->order->otaPlatform) {
                    Log::warning('ProcessResourceCancelOrderJob: 订单没有关联OTA平台，跳过通知', [
                        'order_id' => $this->order->id,
                    ]);
                    // 没有OTA平台，直接更新状态（兼容旧逻辑）
                    $orderService = app(OrderService::class);
                    $orderService->updateOrderStatus(
                        $this->order,
                        OrderStatus::CANCEL_APPROVED,
                        '景区方取消订单成功 - ' . $this->reason
                    );
                    return;
                }
                
                // 先通知OTA平台
                try {
                    $this->notifyOtaOrderCancelled($this->order);
                    
                    Log::info('ProcessResourceCancelOrderJob: OTA平台取消通知成功', [
                        'order_id' => $this->order->id,
                    ]);
                } catch (\Exception $e) {
                    Log::error('ProcessResourceCancelOrderJob: OTA平台取消通知失败，不更新订单状态', [
                        'order_id' => $this->order->id,
                        'ota_platform' => $this->order->otaPlatform->code->value,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    
                    // OTA通知失败，不更新状态，创建异常订单供人工处理
                    $this->createExceptionOrder([
                        'success' => false,
                        'message' => 'OTA平台取消通知失败：' . $e->getMessage(),
                    ]);
                    
                    // 抛出异常，让队列系统重试（如果是临时性错误）
                    throw $e;
                }

                // OTA平台通知成功，开始更新订单状态
                Log::info('ProcessResourceCancelOrderJob: OTA平台通知成功，开始更新订单状态', [
                    'order_id' => $this->order->id,
                ]);

                // 更新订单状态
                $orderService = app(OrderService::class);
                $orderService->updateOrderStatus(
                    $this->order,
                    OrderStatus::CANCEL_APPROVED,
                    '景区方取消订单成功 - ' . $this->reason
                );

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

                Log::info('ProcessResourceCancelOrderJob: 订单状态已更新为取消已确认', [
                    'order_id' => $this->order->id,
                ]);
            } else {
                // 判断是否是临时性错误
                $isTemporaryError = $this->isTemporaryErrorFromResult($cancelResult);
                
                Log::warning('ProcessResourceCancelOrderJob: 景区方取消失败', [
                    'order_id' => $this->order->id,
                    'error' => $cancelResult['message'] ?? '未知错误',
                    'is_temporary_error' => $isTemporaryError,
                    'attempt' => $this->attempts(),
                    'max_attempts' => $this->tries,
                ]);

                // 如果是临时性错误且未达到最大重试次数，抛出异常让队列重试
                if ($isTemporaryError && $this->attempts() < $this->tries) {
                    Log::info('ProcessResourceCancelOrderJob: 临时性错误，将重试', [
                        'order_id' => $this->order->id,
                        'attempt' => $this->attempts(),
                        'next_attempt' => $this->attempts() + 1,
                        'max_attempts' => $this->tries,
                    ]);
                    
                    // 抛出异常，让队列系统自动重试
                    throw new \Exception('景区方取消失败（临时性错误）：' . ($cancelResult['message'] ?? '未知错误'));
                }
                
                // 如果不是临时性错误，或已达到最大重试次数，创建异常订单（不通知携程）
                $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

                $this->createExceptionOrder([
                    'success' => false,
                    'message' => $cancelResult['message'] ?? '景区方取消失败',
                    'can_cancel' => true, // 查询时返回可以取消
                    'cancel_result' => $cancelResult,
                ]);
            }
        } catch (\Exception $e) {
            // 判断是否是临时性错误
            $isTemporaryError = $this->isTemporaryError($e);
            
            Log::error('ProcessResourceCancelOrderJob: 处理异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'is_temporary_error' => $isTemporaryError,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'trace' => $e->getTraceAsString(),
            ]);

            // 如果是临时性错误且未达到最大重试次数，抛出异常让队列重试
            if ($isTemporaryError && $this->attempts() < $this->tries) {
                Log::info('ProcessResourceCancelOrderJob: 临时性错误，将重试', [
                    'order_id' => $this->order->id,
                    'attempt' => $this->attempts(),
                    'next_attempt' => $this->attempts() + 1,
                    'max_attempts' => $this->tries,
                ]);
                
                throw $e; // 抛出异常，让队列系统自动重试
            }
            
            // 如果不是临时性错误，或已达到最大重试次数，创建异常订单
            $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
                'is_temporary_error' => $isTemporaryError,
                'attempts' => $this->attempts(),
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
     * 同步通知，等待成功后再返回
     */
    protected function notifyOtaOrderCancelled(Order $order): void
    {
        $platform = $order->otaPlatform;
        if (!$platform) {
            throw new \Exception('订单没有关联OTA平台');
        }

        // 使用 NotificationFactory 同步通知
        try {
            $notification = \App\Services\OTA\NotificationFactory::create($order);
            if (!$notification) {
                throw new \Exception('无法创建OTA通知服务');
            }
            
            $notification->notifyOrderRefunded($order);
            
            Log::info('ProcessResourceCancelOrderJob: OTA平台取消通知成功', [
                'order_id' => $order->id,
                'ota_platform' => $platform->code->value,
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessResourceCancelOrderJob: OTA平台取消通知失败', [
                'order_id' => $order->id,
                'ota_platform' => $platform->code->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // 重新抛出异常，让调用方处理
            throw $e;
        }
    }

    /**
     * 判断是否是临时性错误（可以重试）
     * 
     * @param \Exception $e 异常对象
     * @return bool true=临时性错误（可重试），false=业务性错误（不可重试）
     */
    protected function isTemporaryError(\Exception $e): bool
    {
        // 超时异常
        if ($e instanceof \Illuminate\Queue\TimeoutException) {
            return true;
        }
        
        // 网络相关异常
        $networkErrorMessages = [
            'Connection refused',
            'Connection timed out',
            'Network is unreachable',
            'Connection reset by peer',
            'Failed to connect',
            'cURL error',
            'GuzzleHttp\Exception\ConnectException',
            'GuzzleHttp\Exception\RequestException',
            'GuzzleHttp\Exception\ServerException',
            'GuzzleHttp\Exception\TransferException',
        ];
        
        $errorMessage = $e->getMessage();
        $errorClass = get_class($e);
        
        foreach ($networkErrorMessages as $networkError) {
            if (stripos($errorMessage, $networkError) !== false || 
                stripos($errorClass, $networkError) !== false) {
                return true;
            }
        }
        
        // HTTP 5xx 错误（服务器错误，可能是临时的）
        if ($e instanceof \Illuminate\Http\Client\RequestException) {
            $statusCode = $e->response?->status() ?? 0;
            if ($statusCode >= 500 && $statusCode < 600) {
                return true;
            }
        }
        
        // 其他情况视为业务性错误，不重试
        return false;
    }

    /**
     * 从结果判断是否是临时性错误
     * 
     * @param array $result 接口返回结果
     * @return bool true=临时性错误（可重试），false=业务性错误（不可重试）
     */
    protected function isTemporaryErrorFromResult(array $result): bool
    {
        $message = $result['message'] ?? '';
        $errorCode = $result['data']->ResultCode ?? '';
        
        // 如果错误信息包含网络相关关键词，视为临时性错误
        $temporaryErrorKeywords = [
            'timeout',
            '超时',
            '连接',
            '网络',
            'Connection',
            'Network',
            'timed out',
        ];
        
        foreach ($temporaryErrorKeywords as $keyword) {
            if (stripos($message, $keyword) !== false) {
                return true;
            }
        }
        
        // 根据横店接口的错误码判断
        // 注意：需要根据实际横店接口文档调整
        // 如果横店接口有明确的错误码定义，可以根据错误码判断
        
        // 默认视为业务性错误，不重试
        return false;
    }
}
