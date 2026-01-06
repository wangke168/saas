<?php

namespace App\Services\OTA\Notifications;

use App\Contracts\OtaNotificationInterface;
use App\Enums\OtaPlatform;
use App\Http\Client\MeituanClient;
use App\Models\Order;
use App\Models\OtaPlatform as OtaPlatformModel;
use Illuminate\Support\Facades\Log;

/**
 * 美团订单状态通知服务
 */
class MeituanNotificationService implements OtaNotificationInterface
{
    protected ?MeituanClient $client = null;

    /**
     * 获取美团客户端
     */
    protected function getClient(): MeituanClient
    {
        if ($this->client === null) {
            // 优先使用环境变量配置（如果存在）
            $config = $this->createConfigFromEnv();
            
            // 如果环境变量配置不存在，尝试从数据库读取
            if (!$config) {
                $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
                $config = $platform?->config;
            }

            if (!$config) {
                throw new \Exception('美团配置不存在，请检查数据库配置或环境变量');
            }

            $this->client = new MeituanClient($config);
        }

        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?\App\Models\OtaConfig
    {
        // 检查环境变量是否存在
        if (!env('MEITUAN_PARTNER_ID') || !env('MEITUAN_APP_KEY') || !env('MEITUAN_APP_SECRET')) {
            return null;
        }

        // 创建临时配置对象（不保存到数据库）
        $config = new \App\Models\OtaConfig();
        $config->account = env('MEITUAN_PARTNER_ID'); // PartnerId存储在account字段
        $config->secret_key = env('MEITUAN_APP_KEY'); // AppKey存储在secret_key字段
        $config->aes_key = env('MEITUAN_APP_SECRET'); // AppSecret存储在aes_key字段
        $config->aes_iv = env('MEITUAN_AES_KEY', ''); // AES密钥存储在aes_iv字段
        
        // API URL 配置
        $config->api_url = env('MEITUAN_API_URL', 'https://openapi.meituan.com');
        $config->callback_url = env('MEITUAN_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 通知订单确认（出票成功）
     */
    public function notifyOrderConfirmed(Order $order): void
    {
        Log::info('MeituanNotificationService: 准备通知美团订单出票成功', [
            'order_id' => $order->id,
            'ota_order_no' => $order->ota_order_no,
            'order_no' => $order->order_no,
        ]);

        try {
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                throw new \Exception('美团配置不存在');
            }

            $client = $this->getClient();

            $requestData = [
                'partnerId' => intval($platform->config->account),
                'body' => [
                    'orderId' => intval($order->ota_order_no),
                    'partnerOrderId' => $order->order_no,
                    'code' => 200,
                    'describe' => '出票成功',
                    'voucherType' => 0, // 不需要支持一码一验，统一使用0
                    'realNameType' => $order->real_name_type ?? 0,
                ],
            ];

            // 如果是实名制订单，返回credentialList
            // 注意：voucherType=0 时，不需要传递voucher字段
            // credentialList的数量应该与订单数量（room_count）一致
            if ($order->real_name_type === 1 && !empty($order->credential_list)) {
                $requestData['body']['credentialList'] = [];
                $roomCount = $order->room_count ?? 1;
                $credentialList = $order->credential_list;
                
                // 确保credentialList的数量与订单数量一致
                // 如果credentialList数量少于订单数量，使用第一个证件信息填充
                // 如果credentialList数量多于订单数量，只取前roomCount个
                for ($i = 0; $i < $roomCount; $i++) {
                    $credential = $credentialList[$i] ?? $credentialList[0] ?? null;
                    if ($credential) {
                        $credentialItem = [
                            'credentialType' => $credential['credentialType'] ?? 0,
                            'credentialNo' => $credential['credentialNo'] ?? '',
                        ];
                        
                        // 只有当voucherType=1（一码一验）时才传递voucher字段
                        // voucherType=0时，不传递voucher字段（即使有值也不传）
                        // 如果voucher为空字符串，也不传递该字段
                        if (!empty($credential['voucher'])) {
                            $credentialItem['voucher'] = $credential['voucher'];
                        }
                        
                        $requestData['body']['credentialList'][] = $credentialItem;
                    }
                }
                
                Log::info('MeituanNotificationService: 构建credentialList', [
                    'order_id' => $order->id,
                    'room_count' => $roomCount,
                    'credential_list_count' => count($credentialList),
                    'final_credential_list_count' => count($requestData['body']['credentialList']),
                ]);
            }

            $result = $client->notifyOrderPay($requestData);

            // 检查响应格式
            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('MeituanNotificationService: 美团订单出票通知成功', [
                    'order_id' => $order->id,
                ]);
            } else {
                // 构建详细的错误信息
                $errorMessage = '美团订单出票通知失败';
                if (isset($result['describe'])) {
                    $errorMessage .= '：' . $result['describe'];
                } elseif (isset($result['message'])) {
                    $errorMessage .= '：' . $result['message'];
                } else {
                    $errorMessage .= '：未知错误';
                }
                
                Log::error('MeituanNotificationService: 美团订单出票通知失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
                
                throw new \Exception($errorMessage);
            }
        } catch (\Exception $e) {
            Log::error('MeituanNotificationService: 美团订单出票通知异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 通知订单退款（取消确认）
     */
    public function notifyOrderRefunded(Order $order): void
    {
        Log::info('MeituanNotificationService: 准备通知美团订单退款成功', [
            'order_id' => $order->id,
            'ota_order_no' => $order->ota_order_no,
            'order_no' => $order->order_no,
        ]);

        try {
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                throw new \Exception('美团配置不存在');
            }

            $client = $this->getClient();

            $requestData = [
                'partnerId' => intval($platform->config->account),
                'body' => [
                    'orderId' => intval($order->ota_order_no),
                    'partnerOrderId' => $order->order_no,
                    'code' => 200,
                    'describe' => '退款成功',
                ],
            ];

            $result = $client->notifyOrderRefund($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('MeituanNotificationService: 美团订单退款通知成功', [
                    'order_id' => $order->id,
                ]);
            } else {
                Log::error('MeituanNotificationService: 美团订单退款通知失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
                throw new \Exception('美团订单退款通知失败：' . ($result['message'] ?? '未知错误'));
            }
        } catch (\Exception $e) {
            Log::error('MeituanNotificationService: 美团订单退款通知异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 通知订单核销（已使用）
     * 注意：美团可能不支持订单核销通知，这里先留空
     */
    public function notifyOrderConsumed(Order $order, array $data = []): void
    {
        Log::info('MeituanNotificationService: 美团暂不支持订单核销通知', [
            'order_id' => $order->id,
        ]);
        // 美团可能不支持订单核销通知，这里先留空
        // 如果后续需要实现，可以在这里添加逻辑
    }
}

