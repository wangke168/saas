<?php

namespace App\Http\Client;

use App\Models\OtaConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MeituanClient
{
    protected OtaConfig $config;

    public function __construct(OtaConfig $config)
    {
        $this->config = $config;
    }

    /**
     * 获取配置中的PartnerId（用于账号验证）
     */
    public function getPartnerId(): int
    {
        return intval($this->config->account ?? 0);
    }

    /**
     * 构建BA认证Header（5个字段）
     * 
     * @param string $method HTTP方法（GET, POST等）
     * @param string $uri URI路径（如：/api/order/pay/notice）
     * @return array
     */
    public function buildAuthHeaders(string $method, string $uri): array
    {
        $partnerId = intval($this->config->account ?? 0); // PartnerId存储在account字段
        $appKey = $this->config->secret_key ?? ''; // AppKey存储在secret_key字段
        $appSecret = $this->config->aes_key ?? ''; // AppSecret存储在aes_key字段（注意：这里aes_key实际存储的是AppSecret）

        // Date: GMT时间格式
        $date = now()->setTimezone('GMT')->format('D, d M Y H:i:s \G\M\T');

        // StringToSign: METHOD + URI + \n + Date
        $stringToSign = strtoupper($method) . " " . $uri . "\n" . $date;

        // Signature: HMAC-SHA1(StringToSign, appSecret)，然后Base64编码
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $appSecret, true));

        // Authorization: MWS appKey:signature
        $authorization = "MWS " . $appKey . ":" . $signature;

        return [
            'PartnerId' => (string)$partnerId,
            'Date' => $date,
            'Authorization' => $authorization,
            'AppKey' => $appKey,
            'X-Encryption-Status' => 'encrypted', // 全局加密标识
        ];
    }

    /**
     * 标准化AES密钥（确保是16字节）
     * 支持多种密钥格式：
     * 1. 十六进制字符串（16字符 = 8字节）→ 重复一次得到16字节
     * 2. 十六进制字符串（32字符 = 16字节）→ 转换为16字节
     * 3. 普通字符串（16字符 = 16字节）→ 直接使用
     * 
     * @param string $keyInput 原始密钥（可能是十六进制字符串或普通字符串）
     * @return string 16字节的密钥
     * @throws \Exception
     */
    protected function normalizeAESKey(string $keyInput): string
    {
        if (empty($keyInput)) {
            throw new \Exception('AES密钥未配置');
        }
        
        // 如果是十六进制字符串
        if (ctype_xdigit($keyInput)) {
            $hexLength = strlen($keyInput);
            
            if ($hexLength === 16) {
                // 16个十六进制字符 = 8字节，重复一次得到16字节
                $keyBytes = hex2bin($keyInput);
                if ($keyBytes === false) {
                    throw new \Exception('AES密钥格式错误：十六进制字符串转换失败');
                }
                return $keyBytes . $keyBytes;
            } elseif ($hexLength === 32) {
                // 32个十六进制字符 = 16字节
                $keyBytes = hex2bin($keyInput);
                if ($keyBytes === false) {
                    throw new \Exception('AES密钥格式错误：十六进制字符串转换失败');
                }
                return $keyBytes;
            } else {
                throw new \Exception('AES密钥格式错误：十六进制字符串长度必须是16或32个字符，当前长度：' . $hexLength);
            }
        }
        
        // 如果是普通字符串，直接使用（假设是16字节）
        $strLength = strlen($keyInput);
        if ($strLength === 16) {
            return $keyInput;
        }
        
        throw new \Exception('AES密钥格式错误：密钥长度必须是16字节，当前长度：' . $strLength . '字节（如果是十六进制字符串，长度应为16或32个字符）');
    }

    /**
     * 生成IV（16位密钥右侧循环移动8位）
     * 
     * @param string $key 16字节密钥
     * @return string
     */
    protected function generateIV(string $key): string
    {
        $keyBytes = $key;
        $ivBytes = '';
        // 将keyBytes右侧循环移动8位（前半部分8位和后半部分8位交换）
        for ($i = 0; $i < 16; $i++) {
            $ivBytes .= $keyBytes[($i + 8) % 16];
        }
        return $ivBytes;
    }

    /**
     * AES加密（全局加密）
     * 
     * @param string $body 待加密的JSON字符串
     * @return string Base64编码的加密字符串
     */
    public function encryptBody(string $body): string
    {
        $keyInput = $this->config->aes_iv ?? ''; // AES密钥存储在aes_iv字段（注意：这里aes_iv实际存储的是AES密钥）
        
        // 标准化密钥（自动处理十六进制字符串转换）
        $key = $this->normalizeAESKey($keyInput);
        
        if (strlen($key) !== 16) {
            throw new \Exception('AES密钥长度必须是16字节，当前长度：' . strlen($key) . '字节');
        }

        $iv = $this->generateIV($key);

        $encrypted = openssl_encrypt($body, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \Exception('AES加密失败');
        }

        return base64_encode($encrypted);
    }

    /**
     * AES解密
     * 
     * @param string $encryptedBody Base64编码的加密字符串
     * @return string 解密后的JSON字符串
     */
    public function decryptBody(string $encryptedBody): string
    {
        $keyInput = $this->config->aes_iv ?? ''; // AES密钥存储在aes_iv字段
        
        // 标准化密钥（自动处理十六进制字符串转换）
        $key = $this->normalizeAESKey($keyInput);
        
        if (strlen($key) !== 16) {
            throw new \Exception('AES密钥长度必须是16字节，当前长度：' . strlen($key) . '字节');
        }

        $iv = $this->generateIV($key);

        $encryptedBytes = base64_decode($encryptedBody);

        if ($encryptedBytes === false) {
            throw new \Exception('Base64解码失败');
        }

        $decrypted = openssl_decrypt($encryptedBytes, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \Exception('AES解密失败');
        }

        return $decrypted;
    }

    /**
     * 通用请求方法（自动处理加密/解密）
     * 
     * @param string $method HTTP方法
     * @param string $url 完整URL
     * @param array $data 请求数据（会被加密）
     * @return array 响应数据（已解密）
     */
    protected function request(string $method, string $url, array $data = []): array
    {
        try {
            // 解析URL获取URI路径
            $parsedUrl = parse_url($url);
            $uri = $parsedUrl['path'] ?? '/';
            if (isset($parsedUrl['query'])) {
                $uri .= '?' . $parsedUrl['query'];
            }

            // 构建BA认证Header
            $headers = $this->buildAuthHeaders($method, $uri);
            $headers['Content-Type'] = 'application/json; charset=utf-8';

            // 美团请求格式：整个请求体是JSON，body字段是加密的字符串
            // 如果data中已经有body字段（已加密），直接使用；否则加密整个data
            $requestBody = '';
            if (!empty($data)) {
                // 如果data中已经有body字段（字符串类型），说明已经加密过了，直接使用
                // 否则，需要加密整个data
                if (isset($data['body']) && is_string($data['body'])) {
                    // body字段已经加密，直接使用
                    $requestBody = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    // 整个请求体加密
                    $jsonBody = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($jsonBody === false) {
                        throw new \Exception('JSON编码失败: ' . json_last_error_msg());
                    }
                    $requestBody = $this->encryptBody($jsonBody);
                }
            }

            Log::info('美团API请求', [
                'url' => $url,
                'method' => $method,
                'uri' => $uri,
                'headers' => $headers,
                'body_length' => strlen($requestBody),
            ]);

            // 发送请求（POST请求时，body作为请求体发送）
            if ($method === 'POST') {
                $response = Http::timeout(30)
                    ->withHeaders($headers)
                    ->withBody($requestBody, 'application/json; charset=utf-8')
                    ->post($url);
            } else {
                $response = Http::timeout(30)
                    ->withHeaders($headers)
                    ->send($method, $url);
            }

            $statusCode = $response->status();
            $rawBody = $response->body();

            Log::info('美团API响应', [
                'url' => $url,
                'status' => $statusCode,
                'body_length' => strlen($rawBody),
                'body_preview' => substr($rawBody, 0, 200),
            ]);

            // 如果响应体为空，直接返回
            if (empty($rawBody)) {
                return [
                    'code' => $statusCode,
                    'describe' => $statusCode === 200 ? 'success' : 'error',
                ];
            }

            // 解密响应体（美团响应也是加密的）
            try {
                $decryptedBody = $this->decryptBody($rawBody);
            } catch (\Exception $e) {
                // 如果解密失败，可能是响应体未加密（某些接口可能不加密）
                Log::warning('美团响应解密失败，尝试直接解析JSON', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                $decryptedBody = $rawBody;
            }

            $responseData = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('美团响应JSON解析失败', [
                    'url' => $url,
                    'error' => json_last_error_msg(),
                    'decrypted_body' => $decryptedBody,
                ]);
                return [
                    'success' => false,
                    'message' => '响应解析失败',
                    'raw' => $decryptedBody,
                ];
            }

            Log::info('美团API响应（解密后）', [
                'url' => $url,
                'response_data' => $responseData,
            ]);

            return $responseData ?? [];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('美团API请求连接异常', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => '网络连接异常：' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('美团API请求异常', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'success' => false,
                'message' => '请求异常：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 多层价格日历变化通知V2（商家调用美团）
     * 
     * @param array $data 请求数据（包含partnerId、startTime、endTime、partnerDealId、body等）
     * @return array
     */
    public function notifyLevelPriceStock(array $data): array
    {
        $url = $this->config->api_url . '/rhone/mtp/api/level/price/notice/v2';
        // 美团请求格式：{partnerId, body: {加密的JSON字符串}}
        // 所以需要将data中的body字段加密
        $requestData = [
            'partnerId' => $data['partnerId'] ?? $this->getPartnerId(),
        ];
        
        // 如果data中有body字段，需要加密
        if (isset($data['body'])) {
            $bodyJson = json_encode($data['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $requestData['body'] = $this->encryptBody($bodyJson);
        }
        
        return $this->request('POST', $url, $requestData);
    }

    /**
     * 订单出票通知（商家调用美团）
     * 
     * @param array $data 请求数据（包含partnerId、body等）
     * @return array
     */
    public function notifyOrderPay(array $data): array
    {
        $url = $this->config->api_url . '/rhone/mtp/api/order/pay/notice';
        // 美团请求格式：{partnerId, body: {加密的JSON字符串}}
        $requestData = [
            'partnerId' => $data['partnerId'] ?? $this->getPartnerId(),
        ];
        
        if (isset($data['body'])) {
            $bodyJson = json_encode($data['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $requestData['body'] = $this->encryptBody($bodyJson);
        }
        
        return $this->request('POST', $url, $requestData);
    }

    /**
     * 订单退款通知（商家调用美团）
     * 
     * @param array $data 请求数据（包含partnerId、body等）
     * @return array
     */
    public function notifyOrderRefund(array $data): array
    {
        $url = $this->config->api_url . '/rhone/mtp/api/order/refund/notice';
        // 美团请求格式：{partnerId, body: {加密的JSON字符串}}
        $requestData = [
            'partnerId' => $data['partnerId'] ?? $this->getPartnerId(),
        ];
        
        if (isset($data['body'])) {
            $bodyJson = json_encode($data['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $requestData['body'] = $this->encryptBody($bodyJson);
        }
        
        return $this->request('POST', $url, $requestData);
    }

    /**
     * 订单消费通知（商家调用美团）
     * 
     * @param array $data 请求数据（包含partnerId、body等）
     * @return array
     */
    public function notifyOrderConsume(array $data): array
    {
        $url = $this->config->api_url . '/rhone/mtp/api/order/consume/notice';
        // 美团请求格式：{partnerId, body: {加密的JSON字符串}}
        $requestData = [
            'partnerId' => $data['partnerId'] ?? $this->getPartnerId(),
        ];
        
        if (isset($data['body'])) {
            $bodyJson = json_encode($data['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $requestData['body'] = $this->encryptBody($bodyJson);
        }
        
        return $this->request('POST', $url, $requestData);
    }

    /**
     * 订单改签通知（商家调用美团）
     * 
     * @param array $data 请求数据（包含partnerId、body等）
     * @return array
     */
    public function notifyOrderReschedule(array $data): array
    {
        $url = $this->config->api_url . '/rhone/mtp/api/order/reschedule/notice';
        // 美团请求格式：{partnerId, body: {加密的JSON字符串}}
        $requestData = [
            'partnerId' => $data['partnerId'] ?? $this->getPartnerId(),
        ];
        
        if (isset($data['body'])) {
            $bodyJson = json_encode($data['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $requestData['body'] = $this->encryptBody($bodyJson);
        }
        
        return $this->request('POST', $url, $requestData);
    }
}
