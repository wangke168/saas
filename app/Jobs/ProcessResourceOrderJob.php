<?php

namespace App\Jobs;

use App\Enums\ExceptionOrderStatus;
use App\Enums\ExceptionOrderType;
use App\Enums\OtaPlatform;
use App\Enums\OrderStatus;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OTA\NotificationFactory;
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
        public string $operation // 'confirm' 或 'cancel'
    ) {
        // 使用 resource-push 队列，确保高优先级处理
        $this->onQueue('resource-push');
    }

    /**
     * 执行任务
     */
    public function handle(
        ResourceServiceFactory $factory,
        InventoryService $inventoryService
    ): void {
        Log::info('ProcessResourceOrderJob 开始执行', [
            'order_id' => $this->order->id,
            'operation' => $this->operation,
            'order_status' => $this->order->status->value,
            'ota_order_no' => $this->order->ota_order_no,
            'attempt' => $this->attempts(), // 当前尝试次数
        ]);
        
        $resourceService = $factory->getService($this->order, 'order');

        if (!$resourceService) {
            Log::warning('ProcessResourceOrderJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
                'operation' => $this->operation,
            ]);
            
            // 无法获取资源方服务，不重试，直接创建异常订单
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务，请检查景区配置（资源配置、软件服务商、同步模式）',
            ]);
            
            return;
        }

        try {
            if ($this->operation === 'confirm') {
                Log::info('ProcessResourceOrderJob: 准备调用资源方接单接口', [
                    'order_id' => $this->order->id,
                    'resource_service_class' => get_class($resourceService),
                    'attempt' => $this->attempts(),
                ]);
                
                $result = $resourceService->confirmOrder($this->order);
                
                Log::info('ProcessResourceOrderJob: 资源方接单接口返回', [
                    'order_id' => $this->order->id,
                    'result_success' => $result['success'] ?? false,
                    'result_message' => $result['message'] ?? '',
                    'attempt' => $this->attempts(),
                ]);
                
                $this->handleConfirmResult($result, $inventoryService);
            } else {
                $reason = 'OTA平台申请取消订单';
                $result = $resourceService->cancelOrder($this->order, $reason);
                $this->handleCancelResult($result, $inventoryService);
            }
        } catch (\Exception $e) {
            // 判断是否是临时性错误
            $isTemporaryError = $this->isTemporaryError($e);
            
            Log::error('ProcessResourceOrderJob: 处理异常', [
                'order_id' => $this->order->id,
                'operation' => $this->operation,
                'error' => $e->getMessage(),
                'is_temporary_error' => $isTemporaryError,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
            ]);

            // 如果是临时性错误且未达到最大重试次数，抛出异常让队列重试
            if ($isTemporaryError && $this->attempts() < $this->tries) {
                Log::info('ProcessResourceOrderJob: 临时性错误，将重试', [
                    'order_id' => $this->order->id,
                    'attempt' => $this->attempts(),
                    'next_attempt' => $this->attempts() + 1,
                    'max_attempts' => $this->tries,
                ]);
                
                throw $e; // 抛出异常，让队列系统自动重试
            }
            
            // 如果不是临时性错误，或已达到最大重试次数，创建异常订单
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
     * 处理接单结果
     */
    protected function handleConfirmResult(array $result, InventoryService $inventoryService): void
    {
        if ($result['success'] ?? false) {
            // 提取资源方订单号
            // HengdianService::confirmOrder() 返回的数据结构：
            // - 如果已有 resource_order_no: data['resource_order_no']
            // - 如果是新接单: data 是 XML 对象，需要从 data->OrderId 提取
            // 注意：HengdianService::confirmOrder() 在成功时可能已经更新了订单的 resource_order_no
            $resourceOrderNo = null;
            if (isset($result['data']['resource_order_no'])) {
                $resourceOrderNo = $result['data']['resource_order_no'];
            } elseif (isset($result['data']->OrderId)) {
                $resourceOrderNo = (string)$result['data']->OrderId;
            }
            
            // 重新加载订单，获取最新的 resource_order_no（HengdianService 可能已经保存）
            $this->order->refresh();
            if (!$resourceOrderNo && $this->order->resource_order_no) {
                $resourceOrderNo = $this->order->resource_order_no;
            }

            Log::info('ProcessResourceOrderJob: 景区方接单成功，准备通知OTA平台', [
                'order_id' => $this->order->id,
                'resource_order_no' => $resourceOrderNo ?: $this->order->resource_order_no,
                'result_data_type' => gettype($result['data'] ?? null),
            ]);

            // 先通知OTA平台，等待成功后再更新订单状态
            // 重新加载订单关联数据，确保 otaPlatform 已加载
            $this->order->load(['otaPlatform']);
            
            // 检查是否有 OTA 平台
            if (!$this->order->otaPlatform) {
                Log::warning('ProcessResourceOrderJob: 订单没有关联 OTA 平台，跳过通知和状态更新', [
                    'order_id' => $this->order->id,
                    'ota_platform_id' => $this->order->ota_platform_id,
                ]);
                // 没有OTA平台，创建异常订单供人工处理
                $this->createExceptionOrder([
                    'success' => false,
                    'message' => '订单没有关联OTA平台，无法通知',
                ]);
                return;
            }
            
            // 判断平台类型
            $isMeituan = $this->order->otaPlatform->code === OtaPlatform::MEITUAN;
            
            Log::info('ProcessResourceOrderJob: 判断平台类型', [
                'order_id' => $this->order->id,
                'ota_platform_code' => $this->order->otaPlatform->code->value,
                'is_meituan' => $isMeituan,
            ]);
            
            // 先通知OTA平台，等待成功后再更新状态
            try {
                if ($isMeituan) {
                    // 美团订单：同步通知
                    Log::info('ProcessResourceOrderJob: 美团订单，同步通知', [
                        'order_id' => $this->order->id,
                    ]);
                    
                    $notification = NotificationFactory::create($this->order);
                    if (!$notification) {
                        throw new \Exception('无法创建美团通知服务');
                    }
                    
                    $notification->notifyOrderConfirmed($this->order);
                    
                    Log::info('ProcessResourceOrderJob: 美团订单同步通知成功', [
                        'order_id' => $this->order->id,
                    ]);
                } else {
                    // 携程订单：同步通知（使用NotificationFactory）
                    Log::info('ProcessResourceOrderJob: 携程订单，同步通知', [
                        'order_id' => $this->order->id,
                    ]);
                    
                    $notification = NotificationFactory::create($this->order);
                    if (!$notification) {
                        throw new \Exception('无法创建携程通知服务');
                    }
                    
                    $notification->notifyOrderConfirmed($this->order);
                    
                    Log::info('ProcessResourceOrderJob: 携程订单同步通知成功', [
                        'order_id' => $this->order->id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('ProcessResourceOrderJob: OTA平台通知失败，不更新订单状态', [
                    'order_id' => $this->order->id,
                    'ota_platform' => $this->order->otaPlatform->code->value,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // OTA通知失败，不更新状态，创建异常订单供人工处理
                $this->createExceptionOrder([
                    'success' => false,
                    'message' => 'OTA平台通知失败：' . $e->getMessage(),
                ]);
                
                // 抛出异常，让队列系统重试（如果是临时性错误）
                throw $e;
            }
            
            // OTA平台通知成功，开始更新订单状态
            Log::info('ProcessResourceOrderJob: OTA平台通知成功，开始更新订单状态', [
                'order_id' => $this->order->id,
            ]);
            
            // 更新订单状态
            $updateData = [
                'status' => OrderStatus::CONFIRMED,
                'confirmed_at' => now(),
            ];
            
            // 只有在 resource_order_no 存在且与当前值不同时才更新
            if ($resourceOrderNo && $this->order->resource_order_no !== $resourceOrderNo) {
                $updateData['resource_order_no'] = $resourceOrderNo;
            }
            
            $this->order->update($updateData);

            Log::info('ProcessResourceOrderJob: 订单状态已更新为已确认', [
                'order_id' => $this->order->id,
                'resource_order_no' => $resourceOrderNo ?: $this->order->resource_order_no,
            ]);
        } else {
            // 判断是否是临时性错误
            $isTemporaryError = $this->isTemporaryErrorFromResult($result);
            
            Log::warning('ProcessResourceOrderJob: 景区方接单失败', [
                'order_id' => $this->order->id,
                'error' => $result['message'] ?? '未知错误',
                'is_temporary_error' => $isTemporaryError,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
            ]);

            // 如果是临时性错误且未达到最大重试次数，抛出异常让队列重试
            if ($isTemporaryError && $this->attempts() < $this->tries) {
                Log::info('ProcessResourceOrderJob: 临时性错误，将重试', [
                    'order_id' => $this->order->id,
                    'attempt' => $this->attempts(),
                    'next_attempt' => $this->attempts() + 1,
                    'max_attempts' => $this->tries,
                ]);
                
                // 抛出异常，让队列系统自动重试
                throw new \Exception('景区方接单失败（临时性错误）：' . ($result['message'] ?? '未知错误'));
            }
            
            // 如果不是临时性错误，或已达到最大重试次数，创建异常订单（不通知OTA平台）
            // 注意：接单失败时不应该通知美团/携程，因为订单实际上没有成功
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
            // 判断是否是临时性错误
            $isTemporaryError = $this->isTemporaryErrorFromResult($result);
            
            Log::warning('ProcessResourceOrderJob: 景区方取消失败', [
                'order_id' => $this->order->id,
                'error' => $result['message'] ?? '未知错误',
                'is_temporary_error' => $isTemporaryError,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
            ]);

            // 如果是临时性错误且未达到最大重试次数，抛出异常让队列重试
            if ($isTemporaryError && $this->attempts() < $this->tries) {
                Log::info('ProcessResourceOrderJob: 临时性错误，将重试', [
                    'order_id' => $this->order->id,
                    'attempt' => $this->attempts(),
                    'next_attempt' => $this->attempts() + 1,
                    'max_attempts' => $this->tries,
                ]);
                
                // 抛出异常，让队列系统自动重试
                throw new \Exception('景区方取消失败（临时性错误）：' . ($result['message'] ?? '未知错误'));
            }
            
            // 如果不是临时性错误，或已达到最大重试次数，创建异常订单（不通知携程）
            $this->order->update(['status' => OrderStatus::CANCEL_REQUESTED]);
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
