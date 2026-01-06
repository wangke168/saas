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
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$platform || !$platform->config) {
                throw new \Exception('美团配置不存在');
            }

            $this->client = new MeituanClient($platform->config);
        }

        return $this->client;
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
                    'voucherType' => 0, // 不需要支持一码一验
                    'realNameType' => $order->real_name_type ?? 0,
                ],
            ];

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

            $result = $client->notifyOrderPay($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('MeituanNotificationService: 美团订单出票通知成功', [
                    'order_id' => $order->id,
                ]);
            } else {
                Log::error('MeituanNotificationService: 美团订单出票通知失败', [
                    'order_id' => $order->id,
                    'result' => $result,
                ]);
                throw new \Exception('美团订单出票通知失败：' . ($result['message'] ?? '未知错误'));
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

