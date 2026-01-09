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
            $client = $this->getClient();
            
            // 根据文档，订单出票通知接口请求格式：
            // {issueType, describe, partnerId, body: {...}}
            // 注意：body 中不应该包含 code 和 describe（这些是响应字段）
            // 使用客户端方法获取 partnerId，确保使用正确的配置
            // 确定realNameType：如果订单有credential_list，强制设置为1
            $realNameType = $order->real_name_type ?? 0;
            if (!empty($order->credential_list)) {
                $realNameType = 1; // 有证件信息，强制设置为实名制
            }
            
            $requestData = [
                'partnerId' => $client->getPartnerId(),
                'issueType' => 1,  // 1=出票成功, 2=出票失败
                'describe' => 'success',
                'body' => [
                    'orderId' => intval($order->ota_order_no),
                    'partnerOrderId' => $order->order_no,
                    'voucherType' => 0, // 不需要支持一码一验，统一使用0
                    'realNameType' => $realNameType, // 确保realNameType=1（如果有credential_list）
                ],
            ];

            // 如果是实名制订单，返回credentialList
            // 根据文档第1165行：realNameType=1时，credentialList中的voucher字段是必传的（不管voucherType）
            // credentialList的数量应该与订单数量（room_count）一致
            // 注意：使用上面确定的realNameType，而不是直接从订单读取（因为可能订单中real_name_type=0但实际有credential_list）
            if ($realNameType === 1 && !empty($order->credential_list)) {
                $requestData['body']['credentialList'] = [];
                $vouchers = [];  // 收集所有凭证码
                $roomCount = $order->room_count ?? 1;
                $credentialList = $order->credential_list;
                
                // 确保credentialList的数量与订单数量一致
                // 如果credentialList数量少于订单数量，记录警告但继续处理（使用已有的证件信息）
                // 如果credentialList数量多于订单数量，只取前roomCount个
                $credentialListCount = count($credentialList);
                if ($credentialListCount < $roomCount) {
                    Log::warning('MeituanNotificationService: credentialList数量少于订单票数', [
                        'order_id' => $order->id,
                        'room_count' => $roomCount,
                        'credential_list_count' => $credentialListCount,
                    ]);
                }
                
                for ($i = 0; $i < $roomCount; $i++) {
                    // 优先使用对应索引的证件信息，如果不存在则使用第一个（但记录警告）
                    $credential = null;
                    if (isset($credentialList[$i])) {
                        $credential = $credentialList[$i];
                    } elseif (!empty($credentialList[0])) {
                        // 如果对应索引不存在，使用第一个（但这不是理想情况）
                        $credential = $credentialList[0];
                        Log::warning('MeituanNotificationService: 使用第一个证件信息填充', [
                            'order_id' => $order->id,
                            'index' => $i,
                            'credential_no' => $credentialList[0]['credentialNo'] ?? '',
                        ]);
                    }
                    
                    if ($credential) {
                        // 生成或获取凭证码
                        // 如果credential中没有voucher字段或voucher为空字符串，则生成一个
                        $voucher = !empty($credential['voucher']) ? $credential['voucher'] : $this->generateVoucher($order, $i);
                        
                        // 确保凭证码不为空
                        if (empty($voucher)) {
                            $voucher = $this->generateVoucher($order, $i);
                        }
                        
                        $credentialItem = [
                            'credentialType' => $credential['credentialType'] ?? 0,
                            'credentialNo' => $credential['credentialNo'] ?? '',
                            'voucher' => $voucher,  // realNameType=1时必传（文档第1165行），且不能为空
                        ];
                        
                        $requestData['body']['credentialList'][] = $credentialItem;
                        $vouchers[] = $voucher;
                    } else {
                        // 如果没有证件信息，记录错误
                        Log::error('MeituanNotificationService: 缺少证件信息', [
                            'order_id' => $order->id,
                            'index' => $i,
                            'room_count' => $roomCount,
                            'credential_list_count' => $credentialListCount,
                        ]);
                    }
                }
                
                // 添加vouchers数组（出票成功时必传字段）
                $requestData['body']['vouchers'] = $vouchers;
                
                // 添加voucherPics数组（出票成功时必传字段）
                // 生成凭证码图片链接，顺序必须与vouchers数组一致
                $requestData['body']['voucherPics'] = $this->generateVoucherPics($vouchers, $order);
                
                Log::info('MeituanNotificationService: 构建credentialList和凭证码', [
                    'order_id' => $order->id,
                    'room_count' => $roomCount,
                    'credential_list_count' => count($credentialList),
                    'final_credential_list_count' => count($requestData['body']['credentialList']),
                    'vouchers_count' => count($vouchers),
                    'voucher_pics_count' => count($requestData['body']['voucherPics']),
                ]);
            } else {
                // 非实名制订单，也需要生成vouchers和voucherPics（如果订单有凭证码）
                // 这里暂时返回空数组，实际应该从订单中获取
                $requestData['body']['vouchers'] = [];
                $requestData['body']['voucherPics'] = [];
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
     * 根据文档，订单退款通知接口需要 refundId（退款流水号）
     */
    public function notifyOrderRefunded(Order $order): void
    {
        Log::info('MeituanNotificationService: 准备通知美团订单退款成功', [
            'order_id' => $order->id,
            'ota_order_no' => $order->ota_order_no,
            'order_no' => $order->order_no,
            'refund_serial_no' => $order->refund_serial_no,
        ]);

        try {
            $client = $this->getClient();
            
            // 获取退款流水号（refundId）
            $refundId = $order->refund_serial_no;
            
            // 如果订单没有退款流水号，可能是订单关闭（不是退款申请）
            // 根据文档，订单退款通知接口用于"美团发起合作方退款申请且商家审核退款通过后"通知美团
            // 订单关闭是美团主动发起的，美团已经知道订单关闭，不需要退款通知
            if (empty($refundId)) {
                Log::info('MeituanNotificationService: 订单没有退款流水号，跳过退款通知', [
                    'order_id' => $order->id,
                    'order_status' => $order->status->value,
                    'reason' => '订单关闭或非退款申请场景，无需通知美团（美团已知道订单关闭）',
                ]);
                // 订单关闭不需要退款通知（美团已经知道订单关闭）
                return;
            }

            // 根据文档，订单退款通知接口请求格式：
            // {code, describe, partnerId, body: {orderId, refundId, partnerOrderId, ...}}
            // 注意：code 和 describe 在外层，不在 body 中
            $requestData = [
                'partnerId' => $client->getPartnerId(),
                'code' => 200,  // 退款成功
                'describe' => 'success',
                'body' => [
                    'orderId' => intval($order->ota_order_no),
                    'refundId' => $refundId,  // 必填：美团退款流水号
                    'partnerOrderId' => $order->order_no,
                    'requestTime' => $order->cancelled_at ? $order->cancelled_at->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s'),
                    'responseTime' => now()->format('Y-m-d H:i:s'),
                ],
            ];

            $result = $client->notifyOrderRefund($requestData);

            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('MeituanNotificationService: 美团订单退款通知成功', [
                    'order_id' => $order->id,
                    'refund_id' => $refundId,
                ]);
            } else {
                // 构建详细的错误信息
                $errorMessage = '美团订单退款通知失败';
                if (isset($result['describe'])) {
                    $errorMessage .= '：' . $result['describe'];
                } elseif (isset($result['message'])) {
                    $errorMessage .= '：' . $result['message'];
                } else {
                    $errorMessage .= '：未知错误';
                }
                
                Log::error('MeituanNotificationService: 美团订单退款通知失败', [
                    'order_id' => $order->id,
                    'refund_id' => $refundId,
                    'result' => $result,
                ]);
                throw new \Exception($errorMessage);
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

    /**
     * 生成凭证码
     * 
     * @param Order $order 订单
     * @param int $index 凭证码索引（从0开始）
     * @return string 凭证码
     */
    protected function generateVoucher(Order $order, int $index): string
    {
        // 凭证码格式：订单号 + 序号（例如：ORD2026010818374262886-1）
        // 如果订单只有1张票，可以只使用订单号
        $roomCount = $order->room_count ?? 1;
        
        if ($roomCount === 1) {
            // 单张票，使用订单号作为凭证码
            return strtoupper($order->order_no);
        } else {
            // 多张票，使用订单号 + 序号
            return strtoupper($order->order_no) . '-' . ($index + 1);
        }
    }

    /**
     * 生成凭证码图片链接
     * 
     * @param array $vouchers 凭证码数组
     * @param Order $order 订单
     * @return array 凭证码图片链接数组
     */
    protected function generateVoucherPics(array $vouchers, Order $order): array
    {
        $voucherPics = [];
        
        // 生成凭证码图片链接
        // 这里使用占位链接，实际应该生成真实的图片链接
        // 图片链接格式可以是：https://your-domain.com/vouchers/{voucher}.png
        $baseUrl = env('APP_URL', 'https://www.laidoulaile.online');
        
        foreach ($vouchers as $voucher) {
            // 生成图片链接（可以是占位链接，或实际生成图片）
            // 注意：图片链接必须可访问，且顺序必须与vouchers数组一致
            $voucherPics[] = $baseUrl . '/vouchers/' . urlencode($voucher) . '.png';
        }
        
        return $voucherPics;
    }
}
