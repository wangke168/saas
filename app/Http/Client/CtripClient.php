<?php

namespace App\Http\Client;

use App\Models\OtaConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CtripClient
{
    protected OtaConfig $config;

    public function __construct(OtaConfig $config)
    {
        $this->config = $config;
    }

    /**
     * 获取配置中的账号（用于账号验证）
     */
    public function getConfigAccount(): string
    {
        return $this->config->account ?? '';
    }

    /**
     * 加密响应体（公共方法，用于webhook响应）
     */
    public function encryptResponseBody(array $bodyData): string
    {
        $jsonBody = json_encode($bodyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            throw new \Exception('JSON编码失败: ' . json_last_error_msg());
        }
        return $this->encrypt($jsonBody);
    }

    /**
     * AES加密（按照携程文档格式）
     * 加密模式为AES-128-CBC，补码方式为PKCS5Padding
     * 使用文档指定的 a-p 编码
     */
    protected function encrypt(string $data): string
    {
        $key = $this->config->aes_key;
        $iv = $this->config->aes_iv;

        // 使用 AES-128-CBC 加密
        $encrypted = openssl_encrypt($data, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \Exception('AES加密失败');
        }

        // 使用文档指定的 a-p 编码
        return $this->encodeBytes($encrypted);
    }

    /**
     * AES解密（按照携程文档格式）
     */
    protected function decrypt(string $encrypted): string
    {
        $key = $this->config->aes_key;
        $iv = $this->config->aes_iv;

        // 使用文档指定的 a-p 解码
        $encryptedBinary = $this->decodeBytes($encrypted);

        $decrypted = openssl_decrypt($encryptedBinary, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \Exception('AES解密失败');
        }

        return $decrypted;
    }

    /**
     * 转16进制变种（按照携程文档的 encodeBytes 方法）
     * 将字节数组转换为字符串，每个字节转换为两个字符（a-p范围）
     */
    protected function encodeBytes(string $bytes): string
    {
        $result = '';
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            // 高4位
            $high = ($byte >> 4) & 0xF;
            // 低4位
            $low = $byte & 0xF;
            // 转换为 a-p 字符（0->a, 1->b, ..., 15->p）
            $result .= chr($high + ord('a'));
            $result .= chr($low + ord('a'));
        }

        return $result;
    }

    /**
     * 转字节数组（按照携程文档的 decodeBytes 方法）
     */
    protected function decodeBytes(string $str): string
    {
        $length = strlen($str);
        if ($length % 2 !== 0) {
            throw new \Exception('编码字符串长度必须是偶数');
        }

        $bytes = '';
        for ($i = 0; $i < $length; $i += 2) {
            $high = ord($str[$i]) - ord('a');
            $low = ord($str[$i + 1]) - ord('a');
            $byte = ($high << 4) | $low;
            $bytes .= chr($byte);
        }

        return $bytes;
    }

    /**
     * 生成签名（按照携程文档格式）
     * MD5(accountId+serviceName+requestTime+body+version+signkey)
     * 注意：这里的 body 是加密后的字符串
     */
    protected function generateSign(string $accountId, string $serviceName, string $requestTime, string $body, string $version = '1.0'): string
    {
        $signString = $accountId . $serviceName . $requestTime . $body . $version . $this->config->secret_key;
        return strtolower(md5($signString));
    }

    /**
     * 发送请求（按照携程文档格式）
     */
    protected function request(string $url, array $requestData): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $requestData);

            // 记录响应状态和原始内容
            $statusCode = $response->status();
            $rawBody = $response->body();
            
            Log::info('携程API响应', [
                'url' => $url,
                'status' => $statusCode,
                'body_length' => strlen($rawBody),
                'body_preview' => substr($rawBody, 0, 200),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                // 如果 json() 返回 null，记录详细信息
                if ($responseData === null) {
                    Log::warning('携程API响应解析为null', [
                        'url' => $url,
                        'status' => $statusCode,
                        'raw_body' => $rawBody,
                        'content_type' => $response->header('Content-Type'),
                    ]);
                    $responseData = [];
                }

                // 解密响应体
                if (isset($responseData['body']) && !empty($responseData['body'])) {
                    try {
                        $decryptedBody = $this->decrypt($responseData['body']);
                        $responseData['body'] = json_decode($decryptedBody, true);
                        Log::info('携程响应解密成功', [
                            'url' => $url,
                            'decrypted_body' => $responseData['body'],
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('携程响应解密失败', [
                            'error' => $e->getMessage(),
                            'body' => $responseData['body'] ?? null,
                        ]);
                    }
                } else {
                    Log::info('携程响应无body字段', [
                        'url' => $url,
                        'response_data' => $responseData,
                    ]);
                }

                return $responseData;
            }

            Log::error('携程API请求失败', [
                'url' => $url,
                'status' => $statusCode,
                'body' => $rawBody,
            ]);

            return ['success' => false, 'message' => '请求失败'];
        } catch (\Exception $e) {
            Log::error('携程API请求异常', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 同步价格（按照携程文档格式）
     * 接口：/DatePriceModify.do 或 /product/price.do（沙箱环境）
     */
    public function syncPrice(array $bodyData): array
    {
        // 优先使用环境变量中的价格API URL
        $url = env('CTRIP_PRICE_API_URL');
        
        // 如果环境变量不存在，使用配置中的URL
        if (!$url) {
            // 如果配置了完整URL，使用配置的；否则根据环境判断
            if ($this->config->api_url && str_contains($this->config->api_url, 'ttdopen.ctrip.com')) {
                // 沙箱环境使用简化的接口路径
                $url = rtrim($this->config->api_url, '/') . '/product/price.do';
            } else {
                // 生产环境使用完整路径
                $url = $this->config->api_url ?: 'https://ttdopen.ctrip.com/api/product/DatePriceModify.do';
                if (!str_ends_with($url, '.do')) {
                    $url = rtrim($url, '/') . '/product/DatePriceModify.do';
                }
            }
        }

        $accountId = $this->config->account;
        $serviceName = 'DatePriceModify';
        $requestTime = date('Y-m-d H:i:s');
        $version = '1.0';

        // 1. 将业务数据转成JSON字符串
        $jsonBody = json_encode($bodyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            Log::error('JSON编码失败', [
                'error' => json_last_error_msg(),
                'data' => $bodyData,
            ]);
            return ['success' => false, 'message' => 'JSON编码失败: ' . json_last_error_msg()];
        }

        // 2. 加密 (得到 a-p 编码的字符串)
        $encryptedBody = $this->encrypt($jsonBody);

        // 3. 签名 (使用加密后的字符串)
        $sign = $this->generateSign($accountId, $serviceName, $requestTime, $encryptedBody, $version);

        // 构建请求数据
        $requestData = [
            'header' => [
                'accountId' => $accountId,
                'serviceName' => $serviceName,
                'requestTime' => $requestTime,
                'version' => $version,
                'sign' => $sign,
            ],
            'body' => $encryptedBody,
        ];

        Log::info('携程价格同步请求', [
            'url' => $url,
            'header' => $requestData['header'],
            'body_length' => strlen($encryptedBody),
            'supplierOptionId' => $bodyData['supplierOptionId'] ?? 'unknown',
        ]);

        return $this->request($url, $requestData);
    }

    /**
     * 同步库存（按照携程文档格式）
     * 接口：/DateInventoryModify.do 或 /product/stock.do（沙箱环境）
     */
    public function syncStock(array $bodyData): array
    {
        // 优先使用环境变量中的库存API URL
        $url = env('CTRIP_STOCK_API_URL');
        
        // 如果环境变量不存在，使用配置中的URL
        if (!$url) {
            // 如果配置了完整URL，使用配置的；否则根据环境判断
            if ($this->config->api_url && str_contains($this->config->api_url, 'ttdopen.ctrip.com')) {
                // 沙箱环境使用简化的接口路径
                $url = rtrim($this->config->api_url, '/') . '/product/stock.do';
            } else {
                // 生产环境使用完整路径
                $url = $this->config->api_url ?: 'https://ttdopen.ctrip.com/api/product/DateInventoryModify.do';
                if (!str_ends_with($url, '.do')) {
                    $url = rtrim($url, '/') . '/product/DateInventoryModify.do';
                }
            }
        }

        $accountId = $this->config->account;
        $serviceName = 'DateInventoryModify';
        $requestTime = date('Y-m-d H:i:s');
        $version = '1.0';

        // 1. 将业务数据转成JSON字符串
        $jsonBody = json_encode($bodyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            Log::error('JSON编码失败', [
                'error' => json_last_error_msg(),
                'data' => $bodyData,
            ]);
            return ['success' => false, 'message' => 'JSON编码失败: ' . json_last_error_msg()];
        }

        // 2. 加密 (得到 a-p 编码的字符串)
        $encryptedBody = $this->encrypt($jsonBody);

        // 3. 签名 (使用加密后的字符串)
        $sign = $this->generateSign($accountId, $serviceName, $requestTime, $encryptedBody, $version);

        // 构建请求数据
        $requestData = [
            'header' => [
                'accountId' => $accountId,
                'serviceName' => $serviceName,
                'requestTime' => $requestTime,
                'version' => $version,
                'sign' => $sign,
            ],
            'body' => $encryptedBody,
        ];

        Log::info('携程库存同步请求', [
            'url' => $url,
            'header' => $requestData['header'],
            'body_length' => strlen($encryptedBody),
            'supplierOptionId' => $bodyData['supplierOptionId'] ?? 'unknown',
        ]);

        return $this->request($url, $requestData);
    }

    /**
     * 确认订单（按照携程文档格式）
     */
    public function confirmOrder(string $orderId, string $confirmNo): array
    {
        $url = $this->config->api_url ?: 'https://ttdopen.ctrip.com/api/order/confirm.do';

        $accountId = $this->config->account;
        $serviceName = 'OrderConfirm';
        $requestTime = date('Y-m-d H:i:s');
        $version = '1.0';

        $bodyData = [
            'orderId' => $orderId,
            'confirmNo' => $confirmNo,
        ];

        // 1. 将业务数据转成JSON字符串
        $jsonBody = json_encode($bodyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            throw new \Exception('JSON编码失败: ' . json_last_error_msg());
        }

        // 2. 加密 (得到 a-p 编码的字符串)
        $encryptedBody = $this->encrypt($jsonBody);

        // 3. 签名 (使用加密后的字符串)
        $sign = $this->generateSign($accountId, $serviceName, $requestTime, $encryptedBody, $version);

        // 构建请求数据
        $requestData = [
            'header' => [
                'accountId' => $accountId,
                'serviceName' => $serviceName,
                'requestTime' => $requestTime,
                'version' => $version,
                'sign' => $sign,
            ],
            'body' => $encryptedBody,
        ];

        return $this->request($url, $requestData);
    }

    /**
     * 验证签名（用于接收携程回调时验证）
     */
    public function verifySign(array $header, string $encryptedBody): bool
    {
        $accountId = $header['accountId'] ?? '';
        $serviceName = $header['serviceName'] ?? '';
        $requestTime = $header['requestTime'] ?? '';
        $version = $header['version'] ?? '1.0';
        $receivedSign = $header['sign'] ?? '';

        // 携程回调时，签名是基于加密后的body计算的
        $expectedSign = $this->generateSign($accountId, $serviceName, $requestTime, $encryptedBody, $version);

        return strtolower($expectedSign) === strtolower($receivedSign);
    }

    /**
     * 解密请求体（用于接收携程回调时解密）
     */
    public function decryptBody(string $encryptedBody): array
    {
        try {
            $decrypted = $this->decrypt($encryptedBody);
            return json_decode($decrypted, true) ?? [];
        } catch (\Exception $e) {
            Log::error('携程请求体解密失败', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * 订单核销通知（按照携程文档格式）
     * 供应商消费后，主动通知携程
     * 
     * @param string $otaOrderId 携程订单号
     * @param string $supplierOrderId 供应商订单号
     * @param string $itemId 订单项编号
     * @param string $useStartDate 实际使用开始日期，格式：yyyy-MM-dd
     * @param string $useEndDate 实际使用结束日期，格式：yyyy-MM-dd
     * @param int $quantity 订单总份数
     * @param int $useQuantity 订单已核销总份数
     * @param array $passengers 已核销的份数所对应的出行人数组，格式：[['passengerId' => 'xxx'], ...]
     * @param array $vouchers 已核销的份数所对应的凭证数组（可选），格式：[['voucherId' => 'xxx'], ...]
     * @return array
     */
    public function notifyOrderConsumed(
        string $otaOrderId,
        string $supplierOrderId,
        string $itemId,
        string $useStartDate,
        string $useEndDate,
        int $quantity,
        int $useQuantity,
        array $passengers = [],
        array $vouchers = []
    ): array {
        // 构建 API URL（订单核销通知接口）
        // 接口URL：https://ttdopen.ctrip.com/api/order/notice.do
        // 接口名称：OrderConsumedNotice
        
        // 优先使用环境变量中的订单API URL
        $url = env('CTRIP_ORDER_API_URL');
        
        // 如果环境变量不存在，使用配置中的URL
        if (!$url) {
            if ($this->config->api_url && str_contains($this->config->api_url, 'ttdopen.ctrip.com')) {
                // 沙箱环境：使用 /api/order/notice.do
                $baseUrl = rtrim($this->config->api_url, '/');
                // 如果 baseUrl 以 /api 结尾，直接拼接；否则添加 /api
                if (str_ends_with($baseUrl, '/api')) {
                    $url = $baseUrl . '/order/notice.do';
                } else {
                    $url = $baseUrl . '/api/order/notice.do';
                }
            } else {
                // 生产环境：使用 /api/order/notice.do
                $url = $this->config->api_url ?: 'https://ttdopen.ctrip.com/api/order/notice.do';
                if (!str_ends_with($url, '.do')) {
                    // 如果URL不以 .do 结尾，检查是否以 /order/notice 结尾
                    if (str_ends_with($url, '/order/notice')) {
                        $url = $url . '.do';
                    } else {
                        $url = rtrim($url, '/') . '/api/order/notice.do';
                    }
                }
            }
        }
        
        Log::info('携程核销通知请求', [
            'url' => $url,
            'otaOrderId' => $otaOrderId,
            'supplierOrderId' => $supplierOrderId,
        ]);

        $accountId = $this->config->account;
        $serviceName = 'OrderConsumedNotice';
        $requestTime = date('Y-m-d H:i:s');
        $version = '1.0';

        // 生成 sequenceId：格式为处理日期（yyyyMMdd）+32位去分隔符的Guid
        $sequenceId = date('Ymd') . str_replace('-', '', \Illuminate\Support\Str::uuid()->toString());

        // 构建 items 数组
        $item = [
            'itemId' => $itemId,
            'useStartDate' => $useStartDate,
            'useEndDate' => $useEndDate,
            'quantity' => $quantity,
            'useQuantity' => $useQuantity,
        ];

        if (!empty($passengers)) {
            $item['passengers'] = $passengers;
        }

        if (!empty($vouchers)) {
            $item['vouchers'] = $vouchers;
        }

        $bodyData = [
            'sequenceId' => $sequenceId,
            'otaOrderId' => $otaOrderId,
            'supplierOrderId' => $supplierOrderId,
            'items' => [$item],
        ];

        // 1. 将业务数据转成JSON字符串
        $jsonBody = json_encode($bodyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            throw new \Exception('JSON编码失败: ' . json_last_error_msg());
        }

        // 2. 加密 (得到 a-p 编码的字符串)
        $encryptedBody = $this->encrypt($jsonBody);

        // 3. 签名 (使用加密后的字符串)
        $sign = $this->generateSign($accountId, $serviceName, $requestTime, $encryptedBody, $version);

        // 构建请求数据
        $requestData = [
            'header' => [
                'accountId' => $accountId,
                'serviceName' => $serviceName,
                'requestTime' => $requestTime,
                'version' => $version,
                'sign' => $sign,
            ],
            'body' => $encryptedBody,
        ];

        return $this->request($url, $requestData);
    }
}
