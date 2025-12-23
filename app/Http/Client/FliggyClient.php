<?php

namespace App\Http\Client;

use App\Models\OtaConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FliggyClient
{
    protected OtaConfig $config;

    public function __construct(OtaConfig $config)
    {
        $this->config = $config;
    }

    /**
     * RSA签名
     */
    protected function sign(string $data): string
    {
        $privateKey = $this->config->rsa_private_key;
        openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    /**
     * 验证RSA签名
     */
    protected function verify(string $data, string $signature): bool
    {
        $publicKey = $this->config->rsa_public_key;
        return openssl_verify($data, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 发送请求
     */
    protected function request(string $url, array $data): array
    {
        try {
            $response = Http::timeout(30)->post($url, $data);
            
            if ($response->successful()) {
                return $response->json();
            }
            
            Log::error('飞猪API请求失败', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            
            return ['success' => false, 'message' => '请求失败'];
        } catch (\Exception $e) {
            Log::error('飞猪API请求异常', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 验证订单
     */
    public function validateOrder(array $orderData): array
    {
        $url = 'https://api.alitrip.alibaba.com/api/v1/hotelticket/validateOrder?format=json';
        
        $params = [
            'distributorId' => $this->config->account,
            'timestamp' => time() * 1000,
        ];
        
        $params = array_merge($params, $orderData);
        
        // 生成签名
        $signString = $params['distributorId'] . '_' . $params['timestamp'] . '_' . ($orderData['productId'] ?? '');
        $params['sign'] = $this->sign($signString);
        
        return $this->request($url, $params);
    }

    /**
     * 取消订单
     */
    public function cancelOrder(string $orderId): array
    {
        $url = 'https://api.alitrip.alibaba.com/api/v1/hotelticket/cancelOrder?format=json';
        
        $params = [
            'distributorId' => $this->config->account,
            'timestamp' => time() * 1000,
            'orderId' => $orderId,
        ];
        
        // 生成签名
        $signString = $params['distributorId'] . '_' . $params['timestamp'] . '_' . $orderId;
        $params['sign'] = $this->sign($signString);
        
        return $this->request($url, $params);
    }
}

