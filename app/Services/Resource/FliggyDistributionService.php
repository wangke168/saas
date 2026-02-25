<?php

namespace App\Services\Resource;

use App\Http\Client\FliggyDistributionClient;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Models\ResourceConfig;
use App\Services\FliggyMappingService;
use App\Services\FliggyOrderDataBuilder;
use App\Services\FliggyOrderStatusMapper;
use App\Enums\ExceptionOrderType;
use App\Enums\ExceptionOrderStatus;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Log;

class FliggyDistributionService implements ResourceServiceInterface
{
    protected ?FliggyDistributionClient $client = null;
    protected ?ResourceConfig $config = null;
    protected FliggyMappingService $mappingService;
    protected FliggyOrderDataBuilder $orderDataBuilder;

    public function __construct(
        FliggyMappingService $mappingService,
        FliggyOrderDataBuilder $orderDataBuilder
    ) {
        $this->mappingService = $mappingService;
        $this->orderDataBuilder = $orderDataBuilder;
    }

    /**
     * 设置资源配置（由 ResourceServiceFactory 调用）
     */
    public function setConfig(ResourceConfig $config): self
    {
        // 确保 softwareProvider 关系已加载（解决队列序列化后关系丢失的问题）
        if (!$config->relationLoaded('softwareProvider') && $config->software_provider_id) {
            $config->load('softwareProvider');
        }
        
        $this->config = $config;
        // 重置客户端和订单数据构建器，以便使用新配置
        $this->client = null;
        $this->orderDataBuilder->setConfig($config);
        return $this;
    }

    /**
     * 获取客户端
     */
    protected function getClient(): FliggyDistributionClient
    {
        if ($this->client === null) {
            if (!$this->config) {
                throw new \Exception('FliggyDistributionService: 配置未设置，请先调用 setConfig()');
            }
            $this->client = new FliggyDistributionClient($this->config);
        }
        return $this->client;
    }

    /**
     * 接单（确认订单）
     * 流程：验证订单 -> 创建订单
     */
    public function confirmOrder(Order $order): array
    {
        Log::info('FliggyDistributionService::confirmOrder 开始', [
            'order_id' => $order->id,
            'resource_order_no' => $order->resource_order_no,
        ]);
        
        try {
            // 如果订单已经有资源方订单号，说明已经接单，直接返回成功
            if ($order->resource_order_no) {
                Log::info('FliggyDistributionService::confirmOrder: 订单已有资源方订单号，直接返回成功', [
                    'order_id' => $order->id,
                    'resource_order_no' => $order->resource_order_no,
                ]);
                
                return [
                    'success' => true,
                    'message' => '订单已确认',
                    'data' => ['resource_order_no' => $order->resource_order_no],
                ];
            }

            // 1. 获取飞猪产品ID
            Log::info('FliggyDistributionService::confirmOrder: 开始获取飞猪产品ID', [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'hotel_id' => $order->hotel_id,
                'room_type_id' => $order->room_type_id,
            ]);
            
            $fliggyProductId = $this->mappingService->getFliggyProductId(
                $order->product_id,
                $order->hotel_id,
                $order->room_type_id
            );
            
            if (!$fliggyProductId) {
                Log::error('FliggyDistributionService::confirmOrder: 未找到飞猪产品映射关系', [
                    'order_id' => $order->id,
                    'product_id' => $order->product_id,
                    'hotel_id' => $order->hotel_id,
                    'room_type_id' => $order->room_type_id,
                ]);
                throw new \Exception('未找到飞猪产品映射关系');
            }
            
            Log::info('FliggyDistributionService::confirmOrder: 获取飞猪产品ID成功', [
                'order_id' => $order->id,
                'fliggy_product_id' => $fliggyProductId,
            ]);
            
            // 2. 构建订单数据（包含实时价格查询）
            $orderData = $this->orderDataBuilder->buildOrderData($order, $fliggyProductId);
            
            // 记录价格差异
            $ourPrice = $order->total_amount ? ($order->total_amount * 100) : 0; // 转换为分
            $fliggyPrice = $orderData['totalPrice'];
            $priceDiff = $fliggyPrice - $ourPrice;
            
            Log::info('FliggyDistributionService::confirmOrder: 价格对比', [
                'order_id' => $order->id,
                'our_price' => $ourPrice,
                'fliggy_price' => $fliggyPrice,
                'price_diff' => $priceDiff,
            ]);
            
            // 3. 订单校验
            Log::info('FliggyDistributionService::confirmOrder: 开始调用订单校验接口', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'fliggy_product_id' => $fliggyProductId,
            ]);
            
            $validateResult = $this->getClient()->validateOrder($orderData);
            
            Log::info('FliggyDistributionService::confirmOrder: 订单校验接口响应', [
                'order_id' => $order->id,
                'success' => $validateResult['success'] ?? false,
                'code' => $validateResult['code'] ?? '',
                'message' => $validateResult['message'] ?? '',
            ]);
            
            if (!($validateResult['success'] ?? false)) {
                $errorMsg = $validateResult['message'] ?? '订单校验失败';
                
                // 检查是否是价格不一致导致的失败
                if (str_contains($errorMsg, '价格') || str_contains($errorMsg, 'price') || str_contains($errorMsg, '4101')) {
                    $this->createExceptionOrder($order, '价格不一致导致订单校验失败', [
                        'fliggy_price' => $fliggyPrice,
                        'our_price' => $ourPrice,
                        'price_diff' => $priceDiff,
                        'validate_result' => $validateResult,
                    ]);
                }
                
                throw new \Exception('订单校验失败：' . $errorMsg);
            }
            
            Log::info('FliggyDistributionService::confirmOrder: 订单校验通过', [
                'order_id' => $order->id,
            ]);
            
            // 4. 创建订单（支持重试）
            Log::info('FliggyDistributionService::confirmOrder: 开始调用创建订单接口', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'fliggy_product_id' => $fliggyProductId,
            ]);
            
            $createResult = $this->createOrderWithRetry($orderData, 3);
            
            Log::info('FliggyDistributionService::confirmOrder: 创建订单接口响应', [
                'order_id' => $order->id,
                'success' => $createResult['success'] ?? false,
                'code' => $createResult['code'] ?? '',
                'message' => $createResult['message'] ?? '',
            ]);
            
            if (!($createResult['success'] ?? false)) {
                throw new \Exception('订单创建失败：' . ($createResult['message'] ?? '未知错误'));
            }
            
            // 5. 提取飞猪订单号
            $orderIds = $createResult['data']['orderIds'] ?? [];
            $fliggyOrderId = !empty($orderIds) ? (string)$orderIds[0] : null;
            
            if (!$fliggyOrderId) {
                throw new \Exception('未获取到飞猪订单号');
            }
            
            // 6. 保存飞猪订单号和结算金额
            $order->update([
                'resource_order_no' => $fliggyOrderId,
                'settlement_amount' => $fliggyPrice / 100, // 转换为元
            ]);
            
            Log::info('FliggyDistributionService::confirmOrder: 订单创建成功', [
                'order_id' => $order->id,
                'fliggy_order_id' => $fliggyOrderId,
                'fliggy_order_ids' => $orderIds,
                'settlement_amount' => $fliggyPrice / 100,
            ]);
            
            return [
                'success' => true,
                'message' => '订单创建成功',
                'data' => [
                    'resource_order_no' => $fliggyOrderId,
                    'fliggy_order_ids' => $orderIds,
                ],
            ];
            
        } catch (\Exception $e) {
            Log::error('FliggyDistributionService::confirmOrder 失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // 创建异常订单
            $this->createExceptionOrder($order, $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * 创建订单（支持重试）
     */
    protected function createOrderWithRetry(array $orderData, int $maxRetries = 3): array
    {
        $lastError = null;
        
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $result = $this->getClient()->createOrder($orderData);
                
                if ($result['success'] ?? false) {
                    if ($i > 0) {
                        Log::info('FliggyDistributionService::createOrderWithRetry: 重试成功', [
                            'attempt' => $i + 1,
                            'max_retries' => $maxRetries,
                        ]);
                    }
                    return $result;
                }
                
                $lastError = $result;
                
                // 如果不是临时性错误，不重试
                if (!$this->isTemporaryError($result)) {
                    Log::info('FliggyDistributionService::createOrderWithRetry: 业务性错误，不重试', [
                        'attempt' => $i + 1,
                        'error' => $result['message'] ?? '未知错误',
                    ]);
                    break;
                }
                
                // 等待后重试
                if ($i < $maxRetries - 1) {
                    Log::info('FliggyDistributionService::createOrderWithRetry: 临时性错误，等待重试', [
                        'attempt' => $i + 1,
                        'next_attempt' => $i + 2,
                        'wait_seconds' => 2,
                    ]);
                    sleep(2);
                }
                
            } catch (\Exception $e) {
                $lastError = [
                    'success' => false,
                    'code' => '5000',
                    'message' => $e->getMessage(),
                ];
                
                if (!$this->isTemporaryErrorFromException($e)) {
                    Log::info('FliggyDistributionService::createOrderWithRetry: 业务性异常，不重试', [
                        'attempt' => $i + 1,
                        'error' => $e->getMessage(),
                    ]);
                    break;
                }
                
                if ($i < $maxRetries - 1) {
                    Log::info('FliggyDistributionService::createOrderWithRetry: 临时性异常，等待重试', [
                        'attempt' => $i + 1,
                        'next_attempt' => $i + 2,
                        'wait_seconds' => 2,
                    ]);
                    sleep(2);
                }
            }
        }
        
        Log::warning('FliggyDistributionService::createOrderWithRetry: 重试失败', [
            'max_retries' => $maxRetries,
            'last_error' => $lastError,
        ]);
        
        return $lastError ?? [
            'success' => false,
            'code' => '5000',
            'message' => '订单创建失败（已重试' . $maxRetries . '次）',
        ];
    }

    /**
     * 取消订单
     */
    public function cancelOrder(Order $order, string $reason): array
    {
        Log::info('FliggyDistributionService::cancelOrder 开始', [
            'order_id' => $order->id,
            'resource_order_no' => $order->resource_order_no,
            'reason' => $reason,
        ]);
        
        try {
            if (!$order->resource_order_no) {
                return [
                    'success' => false,
                    'message' => '订单未关联飞猪订单号，无法取消',
                ];
            }
            
            $result = $this->getClient()->cancelOrder(
                $order->resource_order_no,
                $order->order_no,
                $reason
            );
            
            if ($result['success'] ?? false) {
                Log::info('FliggyDistributionService::cancelOrder: 订单取消成功', [
                    'order_id' => $order->id,
                    'fliggy_order_id' => $order->resource_order_no,
                ]);
                
                return [
                    'success' => true,
                    'message' => '订单取消成功',
                    'data' => $result['data'],
                ];
            }
            
            Log::warning('FliggyDistributionService::cancelOrder: 订单取消失败', [
                'order_id' => $order->id,
                'error' => $result['message'] ?? '未知错误',
            ]);
            
            return [
                'success' => false,
                'message' => $result['message'] ?? '订单取消失败',
                'data' => $result['data'],
            ];
            
        } catch (\Exception $e) {
            Log::error('FliggyDistributionService::cancelOrder 失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * 拒单（飞猪不支持，返回错误）
     */
    public function rejectOrder(Order $order, string $reason): array
    {
        Log::info('FliggyDistributionService::rejectOrder: 飞猪不支持拒单操作', [
            'order_id' => $order->id,
            'reason' => $reason,
        ]);
        
        return [
            'success' => false,
            'message' => '飞猪分销系统不支持拒单操作',
        ];
    }

    /**
     * 核销（通过推送处理）
     */
    public function verifyOrder(Order $order, array $data): array
    {
        Log::info('FliggyDistributionService::verifyOrder: 飞猪核销通过推送通知处理', [
            'order_id' => $order->id,
            'data' => $data,
        ]);
        
        return [
            'success' => true,
            'message' => '核销信息已记录',
        ];
    }

    /**
     * 查询是否可以取消
     */
    public function canCancelOrder(Order $order): array
    {
        try {
            // 查询订单状态
            $statusResult = $this->queryOrderStatus($order);
            
            if (!($statusResult['success'] ?? false)) {
                return [
                    'can_cancel' => false,
                    'message' => '无法查询订单状态：' . ($statusResult['message'] ?? '未知错误'),
                ];
            }
            
            $fliggyStatus = $statusResult['data']['fliggy_status'] ?? null;
            
            // 根据飞猪状态判断是否可以取消
            // 1001(已创建)、1002(已支付) 可以取消
            // 1003(出票成功)、1004(出票失败)、1005(交易完成)、1010(订单关闭) 不可取消
            $canCancel = in_array($fliggyStatus, [1001, 1002]);
            
            return [
                'can_cancel' => $canCancel,
                'message' => $canCancel ? '可以取消' : '订单状态不允许取消（状态：' . FliggyOrderStatusMapper::getStatusDescription($fliggyStatus ?? 0) . '）',
                'data' => [
                    'fliggy_status' => $fliggyStatus,
                    'status_description' => FliggyOrderStatusMapper::getStatusDescription($fliggyStatus ?? 0),
                ],
            ];
            
        } catch (\Exception $e) {
            Log::error('FliggyDistributionService::canCancelOrder 失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'can_cancel' => false,
                'message' => '查询失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询订单状态
     */
    public function queryOrderStatus($orderOrOrderNo): array
    {
        try {
            $order = $orderOrOrderNo instanceof Order 
                ? $orderOrOrderNo 
                : Order::where('order_no', $orderOrOrderNo)->first();
            
            if (!$order) {
                return [
                    'success' => false,
                    'message' => '订单不存在',
                ];
            }
            
            if (!$order->resource_order_no) {
                return [
                    'success' => false,
                    'message' => '订单未关联飞猪订单号',
                ];
            }
            
            $result = $this->getClient()->searchOrder(
                $order->resource_order_no,
                $order->order_no
            );
            
            if (!($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? '查询失败',
                ];
            }
            
            $orderData = $result['data'] ?? [];
            $fliggyStatus = $orderData['orderStatus'] ?? null;
            $ourStatus = FliggyOrderStatusMapper::mapToOurStatus($fliggyStatus ?? 0);
            
            return [
                'success' => true,
                'message' => '查询成功',
                'data' => [
                    'order_no' => $order->order_no,
                    'status' => $ourStatus?->value,
                    'fliggy_status' => $fliggyStatus,
                    'code_infos' => $orderData['codeInfos'] ?? [],
                    'verified_at' => $orderData['verifiedAt'] ?? null,
                    'use_start_date' => $orderData['useStartDate'] ?? null,
                    'use_end_date' => $orderData['useEndDate'] ?? null,
                    'use_quantity' => $orderData['useQuantity'] ?? null,
                ],
            ];
            
        } catch (\Exception $e) {
            Log::error('FliggyDistributionService::queryOrderStatus 失败', [
                'order' => $orderOrOrderNo,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(Order $order, string $message, array $data = []): void
    {
        ExceptionOrder::create([
            'order_id' => $order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => "飞猪接口调用失败：{$message}",
            'exception_data' => array_merge([
                'message' => $message,
                'operation' => 'confirm', // 接单失败，转人工操作时可被 confirmOrder 识别
            ], $data),
            'status' => ExceptionOrderStatus::PENDING,
        ]);
        
        Log::info('FliggyDistributionService: 创建异常订单', [
            'order_id' => $order->id,
            'message' => $message,
        ]);
    }

    /**
     * 判断是否是临时性错误（可以重试）
     */
    protected function isTemporaryError(array $result): bool
    {
        $code = $result['code'] ?? '';
        $message = $result['message'] ?? '';
        
        // 网络错误、超时等可以重试
        if (in_array($code, ['5000', '5001', '5002'])) {
            return true;
        }
        
        // 包含"超时"、"网络"等关键词可以重试
        if (str_contains($message, '超时') || str_contains($message, '网络') || str_contains($message, 'timeout')) {
            return true;
        }
        
        return false;
    }

    /**
     * 判断异常是否是临时性错误
     */
    protected function isTemporaryErrorFromException(\Exception $e): bool
    {
        $message = $e->getMessage();
        
        return str_contains($message, '超时') 
            || str_contains($message, '网络')
            || str_contains($message, 'timeout')
            || $e instanceof \Illuminate\Http\Client\ConnectionException;
    }
}


