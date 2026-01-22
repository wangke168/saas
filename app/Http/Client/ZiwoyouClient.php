<?php

namespace App\Http\Client;

use App\Models\ResourceConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZiwoyouClient
{
    protected ResourceConfig $config;

    public function __construct(ResourceConfig $config)
    {
        Log::info('ZiwoyouClient 构造函数', [
            'config_id' => $config->id ?? 'from_env',
            'config_api_url' => $config->api_url,
        ]);
        
        $this->config = $config;
    }

    /**
     * 获取认证信息（apikey或签名参数）
     */
    protected function getAuthParams(): array
    {
        $authConfig = $this->config->getAuthConfig();
        $params = $authConfig['params'] ?? [];
        
        // 如果认证类型是custom，使用自定义参数
        // 否则尝试从params中获取（兼容其他认证类型）
        $apikey = $params['apikey'] ?? null;
        $custId = $params['custId'] ?? null;
        
        // 如果custId是字符串，转换为整数
        if ($custId !== null && is_string($custId)) {
            $custId = (int)$custId;
        }
        
        return [
            'apikey' => $apikey,
            'custId' => $custId,
        ];
    }

    /**
     * 生成签名（MD5）
     * 签名规则：md5(custId + apikey + timestamp(时间戳毫秒) + 请求json)
     * 
     * @param array $data 请求数据
     * @param string $apikey API密钥
     * @param int $custId 分销商账号
     * @return string MD5签名
     */
    protected function generateSign(array $data, string $apikey, int $custId): string
    {
        $timestamp = time() * 1000; // 毫秒时间戳
        $jsonString = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signString = $custId . $apikey . $timestamp . $jsonString;
        
        Log::info('ZiwoyouClient: 生成签名', [
            'custId' => $custId,
            'timestamp' => $timestamp,
            'json_length' => strlen($jsonString),
            'sign_string_length' => strlen($signString),
        ]);
        
        return md5($signString);
    }

    /**
     * 发送请求
     * 
     * @param string $endpoint API端点
     * @param array $data 请求数据
     * @return array 响应数据
     */
    protected function request(string $endpoint, array $data): array
    {
        try {
            $authParams = $this->getAuthParams();
            $apiUrl = $this->config->api_url;
            
            if (empty($apiUrl)) {
                throw new \Exception('API地址未配置');
            }
            
            // 构建请求参数
            $requestData = array_merge($data, [
                'custId' => $authParams['custId'],
            ]);
            
            // 如果有apikey，直接使用；否则生成签名
            if (!empty($authParams['apikey'])) {
                $requestData['apikey'] = $authParams['apikey'];
                Log::info('ZiwoyouClient: 使用apikey认证', [
                    'endpoint' => $endpoint,
                    'has_apikey' => true,
                ]);
            } else {
                // 使用签名认证（需要apikey和custId）
                if (empty($authParams['apikey']) || empty($authParams['custId'])) {
                    throw new \Exception('签名认证需要apikey和custId，请检查配置');
                }
                
                $timestamp = time() * 1000;
                $requestData['timestamp'] = $timestamp;
                $requestData['sign'] = $this->generateSign($data, $authParams['apikey'], $authParams['custId']);
                
                Log::info('ZiwoyouClient: 使用签名认证', [
                    'endpoint' => $endpoint,
                    'timestamp' => $timestamp,
                    'has_sign' => true,
                ]);
            }
            
            $fullUrl = rtrim($apiUrl, '/') . $endpoint;
            
            Log::info('ZiwoyouClient: 发送请求到自我游接口', [
                'url' => $fullUrl,
                'endpoint' => $endpoint,
                'method' => 'POST',
                'request_data_keys' => array_keys($requestData),
                'request_data' => $requestData, // 记录完整请求数据（敏感信息已处理）
            ]);
            
            // 发送请求
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($fullUrl, $requestData);
            
            $responseBody = $response->body();
            $statusCode = $response->status();
            
            // 记录响应
            Log::info('ZiwoyouClient: API响应', [
                'url' => $fullUrl,
                'status' => $statusCode,
                'body_length' => strlen($responseBody),
            ]);
            
            if ($response->successful()) {
                return $this->parseResponse($response, $responseBody);
            }
            
            Log::error('ZiwoyouClient: API请求失败', [
                'url' => $fullUrl,
                'status' => $statusCode,
                'body' => $responseBody,
            ]);
            
            return [
                'success' => false,
                'state' => -1,
                'msg' => "HTTP请求失败，状态码：{$statusCode}",
            ];
        } catch (\Exception $e) {
            Log::error('ZiwoyouClient: 请求异常', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'state' => -1,
                'msg' => '请求异常：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 解析响应
     * 
     * @param \Illuminate\Http\Client\Response $response HTTP响应
     * @param string $responseBody 响应体
     * @return array 解析后的数据
     */
    protected function parseResponse($response, string $responseBody): array
    {
        try {
            $data = $response->json();
            
            if ($data === null) {
                Log::error('ZiwoyouClient: JSON解析失败', [
                    'body' => $responseBody,
                ]);
                return [
                    'success' => false,
                    'state' => -1,
                    'msg' => '响应格式错误：无法解析JSON',
                ];
            }
            
            $state = $data['state'] ?? -1;
            $msg = $data['msg'] ?? '';
            $responseData = $data['data'] ?? null;
            
            // state=0 表示成功
            $success = $state === 0;
            
            Log::info('ZiwoyouClient: 响应解析结果', [
                'state' => $state,
                'success' => $success,
                'has_data' => $responseData !== null,
            ]);
            
            return [
                'success' => $success,
                'state' => $state,
                'msg' => $msg,
                'data' => $responseData,
            ];
        } catch (\Exception $e) {
            Log::error('ZiwoyouClient: 解析响应失败', [
                'error' => $e->getMessage(),
                'body' => $responseBody,
            ]);
            
            return [
                'success' => false,
                'state' => -1,
                'msg' => '解析响应失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 订单校验（可选）
     * 
     * @param array $data 订单数据
     * @return array 响应数据
     */
    public function validateOrder(array $data): array
    {
        Log::info('ZiwoyouClient: 调用订单校验接口', [
            'endpoint' => '/api/thirdPaty/order/check',
            'order_source_id' => $data['orderSourceId'] ?? null,
            'product_id' => $data['infoId'] ?? null,
            'request_data_keys' => array_keys($data),
        ]);
        
        return $this->request('/api/thirdPaty/order/check', $data);
    }

    /**
     * 创建订单
     * 
     * @param array $data 订单数据
     * @return array 响应数据
     */
    public function createOrder(array $data): array
    {
        Log::info('ZiwoyouClient: 调用创建订单接口', [
            'endpoint' => '/api/thirdPaty/order/add',
            'order_source_id' => $data['orderSourceId'] ?? null,
            'product_id' => $data['infoId'] ?? null,
            'num' => $data['num'] ?? null,
            'travel_date' => $data['travelDate'] ?? null,
            'request_data_keys' => array_keys($data),
        ]);
        
        return $this->request('/api/thirdPaty/order/add', $data);
    }

    /**
     * 查询订单
     * 
     * @param string $orderSourceId 第三方订单号
     * @param int|null $orderId 自我游订单号（可选）
     * @return array 响应数据
     */
    public function queryOrder(string $orderSourceId, ?int $orderId = null): array
    {
        $data = ['orderSourceId' => $orderSourceId];
        if ($orderId) {
            $data['orderId'] = $orderId;
        }
        
        Log::info('ZiwoyouClient: 调用查询订单接口', [
            'endpoint' => '/api/thirdPaty/order/detail',
            'order_source_id' => $orderSourceId,
            'ziwoyou_order_id' => $orderId,
        ]);
        
        return $this->request('/api/thirdPaty/order/detail', $data);
    }

    /**
     * 取消订单
     * 
     * @param int $orderId 自我游订单号
     * @param string $reason 取消原因
     * @return array 响应数据
     */
    public function cancelOrder(int $orderId, string $reason): array
    {
        Log::info('ZiwoyouClient: 调用取消订单接口', [
            'endpoint' => '/api/thirdPaty/order/cancel',
            'ziwoyou_order_id' => $orderId,
            'reason' => $reason,
        ]);
        
        return $this->request('/api/thirdPaty/order/cancel', [
            'orderId' => $orderId,
            'cancelMemo' => $reason,
        ]);
    }

    /**
     * 支付订单
     * 
     * @param int $orderId 自我游订单号
     * @return array 响应数据
     */
    public function payOrder(int $orderId): array
    {
        Log::info('ZiwoyouClient: 调用支付订单接口', [
            'endpoint' => '/api/thirdPaty/order/pay',
            'ziwoyou_order_id' => $orderId,
        ]);
        
        return $this->request('/api/thirdPaty/order/pay', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * 查询余额（可选，用于监控）
     * 
     * @return array 响应数据
     */
    public function queryBalance(): array
    {
        return $this->request('/api/thirdPaty/order/balance', []);
    }
}

