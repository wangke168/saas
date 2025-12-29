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
 * 通知美团订单出票结果
 * 异步通知，14分钟超时
 */
class NotifyMeituanOrderPayJob implements ShouldQueue
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
        public Order $order
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        ResourceServiceFactory $factory
    ): void {
        $resourceService = $factory->getService($this->order);

        if (!$resourceService) {
            Log::warning('NotifyMeituanOrderPayJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 调用资源方接口确认订单
            $result = $resourceService->confirmOrder($this->order);

            if ($result['success'] ?? false) {
                // 资源方确认成功
                $this->order->update([
                    'status' => OrderStatus::CONFIRMED,
                    'confirmed_at' => now(),
                    'resource_order_no' => $result['data']->ConfirmNo ?? '',
                ]);

                Log::info('NotifyMeituanOrderPayJob: 资源方确认成功', [
                    'order_id' => $this->order->id,
                ]);

                // 通知美团出票成功
                $this->notifyMeituan($this->order, 200, '出票成功');
            } else {
                // 资源方确认失败 → 创建异常订单
                Log::warning('NotifyMeituanOrderPayJob: 资源方确认失败', [
                    'order_id' => $this->order->id,
                    'resource_response' => $result,
                ]);
                $this->createExceptionOrder($result);
                // 通知美团出票失败
                $this->notifyMeituan($this->order, 506, $result['message'] ?? '出票失败');
            }
        } catch (\Exception $e) {
            // 超时或异常 → 创建异常订单
            Log::error('NotifyMeituanOrderPayJob: 处理异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);

            // 通知美团出票失败
            $this->notifyMeituan($this->order, 504, '接口超时');
        }
    }

    /**
     * 通知美团订单出票结果
     */
    protected function notifyMeituan(Order $order, int $code, string $describe): void
    {
        try {
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                Log::error('NotifyMeituanOrderPayJob: 美团配置不存在');
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

            // 如果出票成功，添加凭证信息
            if ($code === 200) {
                $requestData['body']['voucherType'] = 0; // 不需要支持一码一验
                $requestData['body']['realNameType'] = $order->real_name_type ?? 0;
                
                // 如果是实名制订单，返回credentialList
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
            }

            $result = $client->notifyOrderPay($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('NotifyMeituanOrderPayJob: 通知美团成功', [
                    'order_id' => $order->id,
                    'code' => $code,
                ]);
            } else {
                Log::error('NotifyMeituanOrderPayJob: 通知美团失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyMeituanOrderPayJob: 通知美团异常', [
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
        $exceptionMessage = '资源方确认失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '资源方确认超时（10秒）';
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'confirm',
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);

        // 更新订单状态为确认中，等待人工处理
        $this->order->update(['status' => OrderStatus::CONFIRMING]);
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
        public Order $order
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        ResourceServiceFactory $factory
    ): void {
        $resourceService = $factory->getService($this->order);

        if (!$resourceService) {
            Log::warning('NotifyMeituanOrderPayJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 调用资源方接口确认订单
            $result = $resourceService->confirmOrder($this->order);

            if ($result['success'] ?? false) {
                // 资源方确认成功
                $this->order->update([
                    'status' => OrderStatus::CONFIRMED,
                    'confirmed_at' => now(),
                    'resource_order_no' => $result['data']->ConfirmNo ?? '',
                ]);

                Log::info('NotifyMeituanOrderPayJob: 资源方确认成功', [
                    'order_id' => $this->order->id,
                ]);

                // 通知美团出票成功
                $this->notifyMeituan($this->order, 200, '出票成功');
            } else {
                // 资源方确认失败 → 创建异常订单
                Log::warning('NotifyMeituanOrderPayJob: 资源方确认失败', [
                    'order_id' => $this->order->id,
                    'resource_response' => $result,
                ]);
                $this->createExceptionOrder($result);
                // 通知美团出票失败
                $this->notifyMeituan($this->order, 506, $result['message'] ?? '出票失败');
            }
        } catch (\Exception $e) {
            // 超时或异常 → 创建异常订单
            Log::error('NotifyMeituanOrderPayJob: 处理异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);

            // 通知美团出票失败
            $this->notifyMeituan($this->order, 504, '接口超时');
        }
    }

    /**
     * 通知美团订单出票结果
     */
    protected function notifyMeituan(Order $order, int $code, string $describe): void
    {
        try {
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                Log::error('NotifyMeituanOrderPayJob: 美团配置不存在');
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

            // 如果出票成功，添加凭证信息
            if ($code === 200) {
                $requestData['body']['voucherType'] = 0; // 不需要支持一码一验
                $requestData['body']['realNameType'] = $order->real_name_type ?? 0;
                
                // 如果是实名制订单，返回credentialList
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
            }

            $result = $client->notifyOrderPay($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('NotifyMeituanOrderPayJob: 通知美团成功', [
                    'order_id' => $order->id,
                    'code' => $code,
                ]);
            } else {
                Log::error('NotifyMeituanOrderPayJob: 通知美团失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyMeituanOrderPayJob: 通知美团异常', [
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
        $exceptionMessage = '资源方确认失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '资源方确认超时（10秒）';
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'confirm',
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);

        // 更新订单状态为确认中，等待人工处理
        $this->order->update(['status' => OrderStatus::CONFIRMING]);
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
        public Order $order
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        ResourceServiceFactory $factory
    ): void {
        $resourceService = $factory->getService($this->order);

        if (!$resourceService) {
            Log::warning('NotifyMeituanOrderPayJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 调用资源方接口确认订单
            $result = $resourceService->confirmOrder($this->order);

            if ($result['success'] ?? false) {
                // 资源方确认成功
                $this->order->update([
                    'status' => OrderStatus::CONFIRMED,
                    'confirmed_at' => now(),
                    'resource_order_no' => $result['data']->ConfirmNo ?? '',
                ]);

                Log::info('NotifyMeituanOrderPayJob: 资源方确认成功', [
                    'order_id' => $this->order->id,
                ]);

                // 通知美团出票成功
                $this->notifyMeituan($this->order, 200, '出票成功');
            } else {
                // 资源方确认失败 → 创建异常订单
                Log::warning('NotifyMeituanOrderPayJob: 资源方确认失败', [
                    'order_id' => $this->order->id,
                    'resource_response' => $result,
                ]);
                $this->createExceptionOrder($result);
                // 通知美团出票失败
                $this->notifyMeituan($this->order, 506, $result['message'] ?? '出票失败');
            }
        } catch (\Exception $e) {
            // 超时或异常 → 创建异常订单
            Log::error('NotifyMeituanOrderPayJob: 处理异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);

            // 通知美团出票失败
            $this->notifyMeituan($this->order, 504, '接口超时');
        }
    }

    /**
     * 通知美团订单出票结果
     */
    protected function notifyMeituan(Order $order, int $code, string $describe): void
    {
        try {
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                Log::error('NotifyMeituanOrderPayJob: 美团配置不存在');
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

            // 如果出票成功，添加凭证信息
            if ($code === 200) {
                $requestData['body']['voucherType'] = 0; // 不需要支持一码一验
                $requestData['body']['realNameType'] = $order->real_name_type ?? 0;
                
                // 如果是实名制订单，返回credentialList
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
            }

            $result = $client->notifyOrderPay($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('NotifyMeituanOrderPayJob: 通知美团成功', [
                    'order_id' => $order->id,
                    'code' => $code,
                ]);
            } else {
                Log::error('NotifyMeituanOrderPayJob: 通知美团失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyMeituanOrderPayJob: 通知美团异常', [
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
        $exceptionMessage = '资源方确认失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '资源方确认超时（10秒）';
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'confirm',
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);

        // 更新订单状态为确认中，等待人工处理
        $this->order->update(['status' => OrderStatus::CONFIRMING]);
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
        public Order $order
    ) {}

    /**
     * 执行任务
     */
    public function handle(
        ResourceServiceFactory $factory
    ): void {
        $resourceService = $factory->getService($this->order);

        if (!$resourceService) {
            Log::warning('NotifyMeituanOrderPayJob: 无法获取资源方服务', [
                'order_id' => $this->order->id,
            ]);
            $this->createExceptionOrder([
                'success' => false,
                'message' => '无法获取资源方服务',
            ]);
            return;
        }

        try {
            // 调用资源方接口确认订单
            $result = $resourceService->confirmOrder($this->order);

            if ($result['success'] ?? false) {
                // 资源方确认成功
                $this->order->update([
                    'status' => OrderStatus::CONFIRMED,
                    'confirmed_at' => now(),
                    'resource_order_no' => $result['data']->ConfirmNo ?? '',
                ]);

                Log::info('NotifyMeituanOrderPayJob: 资源方确认成功', [
                    'order_id' => $this->order->id,
                ]);

                // 通知美团出票成功
                $this->notifyMeituan($this->order, 200, '出票成功');
            } else {
                // 资源方确认失败 → 创建异常订单
                Log::warning('NotifyMeituanOrderPayJob: 资源方确认失败', [
                    'order_id' => $this->order->id,
                    'resource_response' => $result,
                ]);
                $this->createExceptionOrder($result);
                // 通知美团出票失败
                $this->notifyMeituan($this->order, 506, $result['message'] ?? '出票失败');
            }
        } catch (\Exception $e) {
            // 超时或异常 → 创建异常订单
            Log::error('NotifyMeituanOrderPayJob: 处理异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->createExceptionOrder([
                'success' => false,
                'message' => $e->getMessage(),
                'timeout' => $e instanceof \Illuminate\Queue\TimeoutException,
            ]);

            // 通知美团出票失败
            $this->notifyMeituan($this->order, 504, '接口超时');
        }
    }

    /**
     * 通知美团订单出票结果
     */
    protected function notifyMeituan(Order $order, int $code, string $describe): void
    {
        try {
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                Log::error('NotifyMeituanOrderPayJob: 美团配置不存在');
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

            // 如果出票成功，添加凭证信息
            if ($code === 200) {
                $requestData['body']['voucherType'] = 0; // 不需要支持一码一验
                $requestData['body']['realNameType'] = $order->real_name_type ?? 0;
                
                // 如果是实名制订单，返回credentialList
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
            }

            $result = $client->notifyOrderPay($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('NotifyMeituanOrderPayJob: 通知美团成功', [
                    'order_id' => $order->id,
                    'code' => $code,
                ]);
            } else {
                Log::error('NotifyMeituanOrderPayJob: 通知美团失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyMeituanOrderPayJob: 通知美团异常', [
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
        $exceptionMessage = '资源方确认失败：' . ($result['message'] ?? '未知错误');

        if ($result['timeout'] ?? false) {
            $exceptionMessage = '资源方确认超时（10秒）';
        }

        ExceptionOrder::create([
            'order_id' => $this->order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => $exceptionMessage,
            'exception_data' => [
                'operation' => 'confirm',
                'resource_response' => $result,
                'timeout' => $result['timeout'] ?? false,
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);

        // 更新订单状态为确认中，等待人工处理
        $this->order->update(['status' => OrderStatus::CONFIRMING]);
    }
}