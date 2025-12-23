<?php

namespace App\Http\Client;

use App\Models\ResourceConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class HengdianClient
{
    protected ResourceConfig $config;

    public function __construct(ResourceConfig $config)
    {
        $this->config = $config;
    }

    /**
     * 发送XML请求
     */
    protected function request(string $xml): array
    {
        try {
            $url = $this->config->api_url;
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/xml',
                ])
                ->withBody($xml, 'application/xml')
                ->post($url);
            
            if ($response->successful()) {
                return $this->parseXmlResponse($response->body());
            }
            
            Log::error('横店API请求失败', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            
            return ['success' => false, 'message' => '请求失败'];
        } catch (\Exception $e) {
            Log::error('横店API请求异常', [
                'url' => $this->config->api_url,
                'error' => $e->getMessage(),
            ]);
            
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 解析XML响应
     */
    protected function parseXmlResponse(string $xml): array
    {
        try {
            $xmlObj = new SimpleXMLElement($xml);
            
            return [
                'success' => (string)$xmlObj->ResultCode === '0',
                'message' => (string)$xmlObj->Message,
                'data' => $xmlObj,
            ];
        } catch (\Exception $e) {
            Log::error('解析横店XML响应失败', [
                'error' => $e->getMessage(),
                'xml' => $xml,
            ]);
            
            return ['success' => false, 'message' => '解析响应失败'];
        }
    }

    /**
     * 构建XML请求
     */
    protected function buildXml(string $rootElement, array $data): string
    {
        $xml = new SimpleXMLElement("<{$rootElement}></{$rootElement}>");
        
        // 添加认证信息
        $auth = $xml->addChild('AuthenticationToken');
        $auth->addChild('Username', $this->config->username);
        $auth->addChild('Password', $this->config->password);
        
        // 添加其他数据
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild($key);
                foreach ($value as $k => $v) {
                    $child->addChild($k, $v);
                }
            } else {
                $xml->addChild($key, $value);
            }
        }
        
        return $xml->asXML();
    }

    /**
     * 可订查询（ValidateRQ）
     */
    public function validate(array $data): array
    {
        $xml = $this->buildXml('ValidateRQ', $data);
        return $this->request($xml);
    }

    /**
     * 下单预订（BookRQ）
     */
    public function book(array $data): array
    {
        $xml = $this->buildXml('BookRQ', $data);
        return $this->request($xml);
    }

    /**
     * 订单查询（QueryRQ）
     */
    public function query(array $data): array
    {
        $xml = $this->buildXml('QueryRQ', $data);
        return $this->request($xml);
    }

    /**
     * 订单取消（CancelRQ）
     */
    public function cancel(array $data): array
    {
        $xml = $this->buildXml('CancelRQ', $data);
        return $this->request($xml);
    }
}

