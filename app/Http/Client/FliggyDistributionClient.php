<?php

namespace App\Http\Client;

use App\Models\ResourceConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 飞猪分销系统客户端
 * 用于调用飞猪分销系统的API接口
 */
class FliggyDistributionClient
{
    protected ResourceConfig $config;
    protected string $baseUrl;
    protected string $distributorId;
    protected string $privateKey;

    public function __construct(ResourceConfig $config)
    {
        $this->config = $config;
        
        // 从配置中获取基础URL（正式环境或测试环境）
        $this->baseUrl = $config->api_url ?? 'https://api.alitrip.alibaba.com';
        
        // 从 extra_config 中获取 distributorId 和 privateKey
        $extraConfig = $config->extra_config ?? [];
        $this->distributorId = $extraConfig['distributor_id'] ?? '';
        $this->privateKey = $extraConfig['private_key'] ?? '';
        
        if (empty($this->distributorId) || empty($this->privateKey)) {
            throw new \Exception('飞猪分销系统配置不完整：缺少 distributorId 或 privateKey');
        }
        
        Log::info('FliggyDistributionClient 初始化', [
            'base_url' => $this->baseUrl,
            'distributor_id' => $this->distributorId,
            'has_private_key' => !empty($this->privateKey),
        ]);
    }

    /**
     * SHA256withRSA 签名生成
     * 
     * @param string $data 待签名的数据
     * @return string Base64编码的签名
     */
    protected function sign(string $data): string
    {
        try {
            // 处理私钥格式（支持带或不带头尾标记）
            $privateKey = $this->formatPrivateKey($this->privateKey);
            
            // 验证私钥是否有效
            if (!openssl_pkey_get_private($privateKey)) {
                $error = openssl_error_string();
                throw new \Exception('私钥格式无效：' . ($error ?: '未知错误'));
            }
            
            // 使用 SHA256withRSA 算法签名
            $signature = '';
            $result = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            
            if (!$result) {
                $error = openssl_error_string();
                throw new \Exception('签名生成失败：' . ($error ?: '未知错误'));
            }
            
            // Base64编码
            $base64Signature = base64_encode($signature);
            
            Log::debug('飞猪签名生成', [
                'data_length' => strlen($data),
                'signature_length' => strlen($base64Signature),
                'data_preview' => substr($data, 0, 50) . '...',
            ]);
            
            return $base64Signature;
        } catch (\Exception $e) {
            Log::error('飞猪签名生成失败', [
                'data' => $data,
                'data_length' => strlen($data),
                'error' => $e->getMessage(),
                'openssl_errors' => $this->getOpensslErrors(),
            ]);
            throw new \Exception('签名生成失败：' . $e->getMessage());
        }
    }

    /**
     * 获取所有 OpenSSL 错误信息
     * 
     * @return array
     */
    protected function getOpensslErrors(): array
    {
        $errors = [];
        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }
        return $errors;
    }

    /**
     * 格式化私钥（确保包含头尾标记）
     * 
     * @param string $key 原始私钥
     * @return string 格式化后的私钥
     */
    protected function formatPrivateKey(string $key): string
    {
        // 去除首尾空白
        $key = trim($key);
        
        // 如果已经包含头尾标记，直接返回
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        
        // 如果没有头尾标记，添加
        $key = str_replace(["\r", "\n"], '', $key);
        $key = chunk_split($key, 64, "\n");
        return "-----BEGIN PRIVATE KEY-----\n" . $key . "-----END PRIVATE KEY-----\n";
    }

    /**
     * 验证RSA签名
     * 
     * @param string $data 原始数据
     * @param string $signature Base64编码的签名
     * @param string $publicKey 公钥
     * @return bool
     */
    public function verify(string $data, string $signature, string $publicKey): bool
    {
        try {
            $publicKey = $this->formatPublicKey($publicKey);
            $signatureBinary = base64_decode($signature);
            
            return openssl_verify($data, $signatureBinary, $publicKey, OPENSSL_ALGO_SHA256) === 1;
        } catch (\Exception $e) {
            Log::error('飞猪签名验证失败', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 格式化公钥
     * 
     * @param string $key 原始公钥
     * @return string 格式化后的公钥
     */
    protected function formatPublicKey(string $key): string
    {
        $key = trim($key);
        
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        
        $key = str_replace(["\r", "\n"], '', $key);
        $key = chunk_split($key, 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n" . $key . "-----END PUBLIC KEY-----\n";
    }

    /**
     * 发送HTTP请求
     * 
     * @param string $url 请求URL
     * @param array $params 请求参数
     * @return array 响应数据
     */
    protected function request(string $url, array $params): array
    {
        try {
            Log::info('飞猪分销API请求', [
                'url' => $url,
                'params' => $this->maskSensitiveData($params),
            ]);
            
            $response = Http::timeout(30)
                ->asJson()
                ->post($url, $params);
            
            $responseData = $response->json();
            $responseCode = $responseData['code'] ?? '';
            
            // 记录完整的响应（用于调试）
            Log::info('飞猪分销API响应', [
                'url' => $url,
                'http_status' => $response->status(),
                'response_code' => $responseCode,
                'response_message' => $responseData['message'] ?? '',
                'response_body' => $responseData,
            ]);
            
            if ($response->successful()) {
                $isSuccess = $responseCode == '2000';
                
                if ($isSuccess) {
                    Log::info('飞猪分销API请求成功', [
                        'url' => $url,
                        'response_code' => $responseCode,
                    ]);
                } else {
                    Log::warning('飞猪分销API请求返回错误码', [
                        'url' => $url,
                        'response_code' => $responseCode,
                        'response_message' => $responseData['message'] ?? '',
                    ]);
                }
                
                return [
                    'success' => $isSuccess,
                    'code' => $responseCode,
                    'message' => $responseData['message'] ?? '',
                    'data' => $responseData['data'] ?? $responseData,
                ];
            }
            
            Log::error('飞猪分销API请求失败', [
                'url' => $url,
                'http_status' => $response->status(),
                'response_code' => $responseCode,
                'response_body' => $response->body(),
            ]);
            
            return [
                'success' => false,
                'code' => $responseCode ?: (string)$response->status(),
                'message' => $responseData['message'] ?? 'HTTP请求失败：' . $response->status(),
                'data' => $responseData,
            ];
        } catch (\Exception $e) {
            Log::error('飞猪分销API请求异常', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'code' => '5000',
                'message' => '请求异常：' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * 掩码敏感数据（用于日志）
     * 
     * @param array $data
     * @return array
     */
    protected function maskSensitiveData(array $data): array
    {
        $masked = $data;
        if (isset($masked['sign'])) {
            $masked['sign'] = substr($masked['sign'], 0, 20) . '...';
        }
        return $masked;
    }

    /**
     * 构建公共参数并生成签名
     * 
     * @param array $params 业务参数
     * @param string $signFormula 签名公式（如：distributorId_timestamp_productId）
     * @return array 包含公共参数和签名的完整参数
     */
    protected function buildParams(array $params, string $signFormula): array
    {
        $timestamp = time() * 1000; // 13位时间戳（毫秒）
        
        // 确保 distributorId 是字符串（飞猪接口要求）
        $distributorId = (string)$this->distributorId;
        
        // 确保 timestamp 是整数（Long 类型）
        $timestamp = (int)$timestamp;
        
        $allParams = array_merge([
            'distributorId' => $distributorId,
            'timestamp' => $timestamp,
        ], $params);
        
        // 确保所有数值参数都是正确的类型
        $allParams = $this->normalizeParams($allParams);
        
        // 根据签名公式生成签名字符串
        $signString = $this->buildSignString($signFormula, $allParams);
        
        // 生成签名
        $allParams['sign'] = $this->sign($signString);
        
        return $allParams;
    }

    /**
     * 规范化参数类型
     * 确保数值参数是正确的类型
     * 
     * @param array $params
     * @return array
     */
    protected function normalizeParams(array $params): array
    {
        $normalized = [];
        
        foreach ($params as $key => $value) {
            // 对于整数参数，确保是整数类型
            if (in_array($key, ['timestamp', 'pageNo', 'pageSize', 'beginTime', 'endTime'])) {
                $normalized[$key] = (int)$value;
            }
            // 对于数组参数（如 productIds），保持原样
            elseif (is_array($value)) {
                $normalized[$key] = $value;
            }
            // 其他参数转为字符串
            else {
                $normalized[$key] = (string)$value;
            }
        }
        
        return $normalized;
    }

    /**
     * 根据签名公式构建签名字符串
     * 
     * @param string $formula 签名公式（如：distributorId_timestamp_productId）
     * @param array $params 参数数组
     * @return string 签名字符串
     */
    protected function buildSignString(string $formula, array $params): string
    {
        // 检查公式是否以 _ 结尾（如：distributorId_timestamp_）
        $endsWithUnderscore = str_ends_with($formula, '_');
        
        // 解析公式，提取参数名
        $parts = explode('_', rtrim($formula, '_'));
        $signParts = [];
        
        foreach ($parts as $part) {
            if (empty($part)) {
                continue; // 跳过空的部分
            }
            
            $value = $params[$part] ?? '';
            
            // 如果参数为空，根据文档说明可能不拼接
            if ($value === '') {
                continue;
            }
            
            // 处理数组类型的参数（如 productIds）
            if (is_array($value)) {
                // 对于数组参数，根据参数名特殊处理
                if ($part === 'productIds' && !empty($value)) {
                    // productIds 使用第一个元素
                    $value = $value[0];
                } else {
                    // 其他数组参数，转换为 JSON 字符串（但通常不应该出现在签名公式中）
                    Log::warning('飞猪签名：数组参数出现在签名公式中', [
                        'param_name' => $part,
                        'formula' => $formula,
                    ]);
                    continue; // 跳过数组参数，避免错误
                }
            }
            
            // 转换为字符串
            $signParts[] = (string)$value;
        }
        
        $signString = implode('_', $signParts);
        
        // 如果公式以 _ 结尾，签名字符串也应该以 _ 结尾
        if ($endsWithUnderscore) {
            $signString .= '_';
        }
        
        // 记录签名字符串（用于调试）
        Log::debug('飞猪签名字符串构建', [
            'formula' => $formula,
            'sign_string' => $signString,
            'params' => array_intersect_key($params, array_flip($parts)),
        ]);
        
        return $signString;
    }

    /**
     * 1. 批量获取产品基本信息（分页）
     * 
     * @param int $pageNo 页码
     * @param int $pageSize 页大小（1-100）
     * @return array
     */
    public function queryProductBaseInfoByPage(int $pageNo = 1, int $pageSize = 20): array
    {
        $url = $this->baseUrl . '/api/v1/hotelticket/queryProductBaseInfoByPage?format=json';
        
        // 确保参数类型正确
        $pageNo = (int)$pageNo;
        $pageSize = min(max((int)$pageSize, 1), 100); // 限制在1-100之间
        
        $params = $this->buildParams([
            'pageNo' => $pageNo,
            'pageSize' => $pageSize,
        ], 'distributorId_timestamp_');
        
        Log::info('飞猪分页查询产品', [
            'url' => $url,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
            'distributor_id' => $this->distributorId,
            'timestamp' => $params['timestamp'],
        ]);
        
        return $this->request($url, $params);
    }

    /**
     * 2. 批量获取产品基本信息（按ID）
     * 
     * @param array $productIds 产品ID列表（最多100个）
     * @return array
     */
    public function queryProductBaseInfoByIds(array $productIds): array
    {
        if (empty($productIds)) {
            return [
                'success' => false,
                'code' => '4001',
                'message' => '产品ID列表不能为空',
                'data' => null,
            ];
        }
        
        if (count($productIds) > 100) {
            return [
                'success' => false,
                'code' => '4001',
                'message' => '产品ID列表最多100个',
                'data' => null,
            ];
        }
        
        $url = $this->baseUrl . '/api/v1/hotelticket/queryProductBaseInfoByIds?format=json';
        
        // 根据文档，签名公式使用第一个productId
        // 注意：虽然参数名是 productIds（数组），但签名时使用第一个 productId
        $firstProductId = $productIds[0] ?? '';
        
        // 构建参数：签名公式中使用 productId（单数），但请求参数中使用 productIds（数组）
        $params = $this->buildParams([
            'productIds' => $productIds,  // 请求参数：数组
            'productId' => $firstProductId, // 用于签名：第一个ID
        ], 'distributorId_timestamp_productId');
        
        // 移除用于签名的 productId，只保留 productIds 数组
        unset($params['productId']);
        
        return $this->request($url, $params);
    }

    /**
     * 3. 获取产品详情（单体）
     * 
     * @param string $productId 产品ID
     * @return array
     */
    public function queryProductDetailInfo(string $productId): array
    {
        if (empty($productId)) {
            return [
                'success' => false,
                'code' => '4001',
                'message' => '产品ID不能为空',
                'data' => null,
            ];
        }
        
        $url = $this->baseUrl . '/api/v1/hotelticket/queryProductDetailInfo?format=json';
        
        $params = $this->buildParams([
            'productId' => $productId,
        ], 'distributorId_timestamp_productId');
        
        return $this->request($url, $params);
    }

    /**
     * 4. 批量获取价格/库存
     * 
     * @param string $productId 产品ID
     * @param int|null $beginTime 开始时间戳（可选）
     * @param int|null $endTime 结束时间戳（可选）
     * @return array
     */
    public function queryProductPriceStock(string $productId, ?int $beginTime = null, ?int $endTime = null): array
    {
        if (empty($productId)) {
            return [
                'success' => false,
                'code' => '4001',
                'message' => '产品ID不能为空',
                'data' => null,
            ];
        }
        
        $url = $this->baseUrl . '/api/v1/hotelticket/queryProductPriceStock?format=json';
        
        $params = [];
        if ($beginTime !== null) {
            $params['beginTime'] = $beginTime;
        }
        if ($endTime !== null) {
            $params['endTime'] = $endTime;
        }
        
        $params = $this->buildParams(array_merge([
            'productId' => $productId,
        ], $params), 'distributorId_timestamp_productId');
        
        return $this->request($url, $params);
    }
}

