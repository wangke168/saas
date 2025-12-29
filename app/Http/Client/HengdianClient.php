<?php

namespace App\Http\Client;

use App\Models\ResourceConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class HengdianClient
{
    protected ResourceConfig $config;
    protected ?string $otaPlatformCode = null;  // OTA平台代码，用于选择对应的认证信息

    public function __construct(ResourceConfig $config, ?string $otaPlatformCode = null)
    {
        Log::info('HengdianClient 构造函数', [
            'config_id' => $config->id ?? 'from_env',
            'config_api_url' => $config->api_url,
            'ota_platform_code' => $otaPlatformCode,
            'has_credentials' => !empty($config->extra_config['credentials'] ?? []),
        ]);
        
        $this->config = $config;
        $this->otaPlatformCode = $otaPlatformCode;
    }

    /**
     * 获取认证信息（根据OTA平台选择）
     */
    protected function getCredentials(): array
    {
        Log::info('HengdianClient::getCredentials 开始', [
            'ota_platform_code' => $this->otaPlatformCode,
        ]);
        
        // 如果有指定OTA平台，且配置中有该平台的认证信息，使用平台专用认证
        if ($this->otaPlatformCode) {
            $extraConfig = $this->config->extra_config ?? [];
            $credentials = $extraConfig['credentials'][$this->otaPlatformCode] ?? null;
            
            Log::info('HengdianClient::getCredentials: 检查平台专用认证', [
                'ota_platform_code' => $this->otaPlatformCode,
                'has_credentials' => $credentials !== null,
                'has_username' => isset($credentials['username']),
                'has_password' => isset($credentials['password']),
            ]);
            
            if ($credentials && isset($credentials['username']) && isset($credentials['password'])) {
                Log::info('HengdianClient::getCredentials: 使用平台专用认证', [
                    'ota_platform_code' => $this->otaPlatformCode,
                    'username' => $credentials['username'],
                ]);
                
                return [
                    'username' => $credentials['username'],
                    'password' => $credentials['password'],
                ];
            }
        }

        // 否则使用默认认证信息
        Log::info('HengdianClient::getCredentials: 使用默认认证', [
            'username' => $this->config->username,
        ]);
        
        return [
            'username' => $this->config->username,
            'password' => $this->config->password,
        ];
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
            
            Log::error('资源方API请求失败', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            
            return ['success' => false, 'message' => '请求失败'];
        } catch (\Exception $e) {
            Log::error('资源方API请求异常', [
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
            Log::error('解析资源方XML响应失败', [
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
        
        // 添加认证信息（根据OTA平台动态选择）
        $auth = $xml->addChild('AuthenticationToken');
        $credentials = $this->getCredentials();
        $auth->addChild('Username', $credentials['username']);
        $auth->addChild('Password', $credentials['password']);
        
        // 递归添加其他数据
        $this->addXmlChildren($xml, $data);
        
        return $xml->asXML();
    }

    /**
     * 递归添加XML子元素（支持复杂嵌套结构）
     */
    protected function addXmlChildren(\SimpleXMLElement $xml, array $data): void
    {
        foreach ($data as $key => $value) {
            // 跳过 null 值
            if ($value === null) {
                continue;
            }
            
            if (is_array($value)) {
                // 判断是否为索引数组（列表）
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // 索引数组：创建多个同名子元素
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $child = $xml->addChild($key);
                            $this->addXmlChildren($child, $item);
                        } else {
                            $xml->addChild($key, htmlspecialchars((string)$item, ENT_XML1, 'UTF-8'));
                        }
                    }
                } else {
                    // 关联数组：创建单个子元素
                    $child = $xml->addChild($key);
                    $this->addXmlChildren($child, $value);
                }
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
            }
        }
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
        // 记录原始数据（用于调试）
        Log::info('横店下单原始数据', [
            'data' => $data,
            'OrderGuests' => $data['OrderGuests'] ?? null,
            'OrderGuests_is_null' => is_null($data['OrderGuests'] ?? null),
            'OrderGuests_is_array' => is_array($data['OrderGuests'] ?? null),
            'OrderGuests_structure' => $data['OrderGuests'] ?? null,
        ]);
        
        $xml = $this->buildXml('BookRQ', $data);
        
        // 验证 XML 中是否包含 OrderGuests 节点
        $hasOrderGuests = strpos($xml, '<OrderGuests>') !== false;
        $orderGuestsContent = '';
        $orderGuestCount = 0;
        
        if ($hasOrderGuests) {
            // 提取 OrderGuests 节点的内容
            preg_match('/<OrderGuests>(.*?)<\/OrderGuests>/s', $xml, $matches);
            $orderGuestsContent = $matches[1] ?? '';
            
            // 统计 OrderGuest 节点数量
            $orderGuestCount = substr_count($orderGuestsContent, '<OrderGuest>');
        }
        
        // 记录发送到景区方系统的 XML 数据
        Log::info('发送到景区方系统的 XML 请求', [
            'api' => 'BookRQ',
            'url' => $this->config->api_url,
            'xml' => $xml,
            'xml_length' => strlen($xml),
            'has_order_guests' => $hasOrderGuests,
            'order_guest_count' => $orderGuestCount,
            'order_guests_content' => $orderGuestsContent,
        ]);
        
        return $this->request($xml);
    }

    /**
     * 订单查询（QueryStatusRQ）
     */
    public function query(array $data): array
    {
        $xml = $this->buildXml('QueryStatusRQ', $data);
        return $this->request($xml);
    }

    /**
     * 动态订阅（SubscribeRoomStatusRQ）
     */
    public function subscribeRoomStatus(array $data): array
    {
        $xml = $this->buildXml('SubscribeRoomStatusRQ', $data);
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


        $auth->addChild('Password', $credentials['password']);
        
        // 递归添加其他数据
        $this->addXmlChildren($xml, $data);
        
        return $xml->asXML();
    }

    /**
     * 递归添加XML子元素（支持复杂嵌套结构）
     */
    protected function addXmlChildren(\SimpleXMLElement $xml, array $data): void
    {
        foreach ($data as $key => $value) {
            // 跳过 null 值
            if ($value === null) {
                continue;
            }
            
            if (is_array($value)) {
                // 判断是否为索引数组（列表）
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // 索引数组：创建多个同名子元素
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $child = $xml->addChild($key);
                            $this->addXmlChildren($child, $item);
                        } else {
                            $xml->addChild($key, htmlspecialchars((string)$item, ENT_XML1, 'UTF-8'));
                        }
                    }
                } else {
                    // 关联数组：创建单个子元素
                    $child = $xml->addChild($key);
                    $this->addXmlChildren($child, $value);
                }
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
            }
        }
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
        // 记录原始数据（用于调试）
        Log::info('横店下单原始数据', [
            'data' => $data,
            'OrderGuests' => $data['OrderGuests'] ?? null,
            'OrderGuests_is_null' => is_null($data['OrderGuests'] ?? null),
            'OrderGuests_is_array' => is_array($data['OrderGuests'] ?? null),
            'OrderGuests_structure' => $data['OrderGuests'] ?? null,
        ]);
        
        $xml = $this->buildXml('BookRQ', $data);
        
        // 验证 XML 中是否包含 OrderGuests 节点
        $hasOrderGuests = strpos($xml, '<OrderGuests>') !== false;
        $orderGuestsContent = '';
        $orderGuestCount = 0;
        
        if ($hasOrderGuests) {
            // 提取 OrderGuests 节点的内容
            preg_match('/<OrderGuests>(.*?)<\/OrderGuests>/s', $xml, $matches);
            $orderGuestsContent = $matches[1] ?? '';
            
            // 统计 OrderGuest 节点数量
            $orderGuestCount = substr_count($orderGuestsContent, '<OrderGuest>');
        }
        
        // 记录发送到景区方系统的 XML 数据
        Log::info('发送到景区方系统的 XML 请求', [
            'api' => 'BookRQ',
            'url' => $this->config->api_url,
            'xml' => $xml,
            'xml_length' => strlen($xml),
            'has_order_guests' => $hasOrderGuests,
            'order_guest_count' => $orderGuestCount,
            'order_guests_content' => $orderGuestsContent,
        ]);
        
        return $this->request($xml);
    }

    /**
     * 订单查询（QueryStatusRQ）
     */
    public function query(array $data): array
    {
        $xml = $this->buildXml('QueryStatusRQ', $data);
        return $this->request($xml);
    }

    /**
     * 动态订阅（SubscribeRoomStatusRQ）
     */
    public function subscribeRoomStatus(array $data): array
    {
        $xml = $this->buildXml('SubscribeRoomStatusRQ', $data);
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


        $auth->addChild('Password', $credentials['password']);
        
        // 递归添加其他数据
        $this->addXmlChildren($xml, $data);
        
        return $xml->asXML();
    }

    /**
     * 递归添加XML子元素（支持复杂嵌套结构）
     */
    protected function addXmlChildren(\SimpleXMLElement $xml, array $data): void
    {
        foreach ($data as $key => $value) {
            // 跳过 null 值
            if ($value === null) {
                continue;
            }
            
            if (is_array($value)) {
                // 判断是否为索引数组（列表）
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // 索引数组：创建多个同名子元素
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $child = $xml->addChild($key);
                            $this->addXmlChildren($child, $item);
                        } else {
                            $xml->addChild($key, htmlspecialchars((string)$item, ENT_XML1, 'UTF-8'));
                        }
                    }
                } else {
                    // 关联数组：创建单个子元素
                    $child = $xml->addChild($key);
                    $this->addXmlChildren($child, $value);
                }
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
            }
        }
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
        // 记录原始数据（用于调试）
        Log::info('横店下单原始数据', [
            'data' => $data,
            'OrderGuests' => $data['OrderGuests'] ?? null,
            'OrderGuests_is_null' => is_null($data['OrderGuests'] ?? null),
            'OrderGuests_is_array' => is_array($data['OrderGuests'] ?? null),
            'OrderGuests_structure' => $data['OrderGuests'] ?? null,
        ]);
        
        $xml = $this->buildXml('BookRQ', $data);
        
        // 验证 XML 中是否包含 OrderGuests 节点
        $hasOrderGuests = strpos($xml, '<OrderGuests>') !== false;
        $orderGuestsContent = '';
        $orderGuestCount = 0;
        
        if ($hasOrderGuests) {
            // 提取 OrderGuests 节点的内容
            preg_match('/<OrderGuests>(.*?)<\/OrderGuests>/s', $xml, $matches);
            $orderGuestsContent = $matches[1] ?? '';
            
            // 统计 OrderGuest 节点数量
            $orderGuestCount = substr_count($orderGuestsContent, '<OrderGuest>');
        }
        
        // 记录发送到景区方系统的 XML 数据
        Log::info('发送到景区方系统的 XML 请求', [
            'api' => 'BookRQ',
            'url' => $this->config->api_url,
            'xml' => $xml,
            'xml_length' => strlen($xml),
            'has_order_guests' => $hasOrderGuests,
            'order_guest_count' => $orderGuestCount,
            'order_guests_content' => $orderGuestsContent,
        ]);
        
        return $this->request($xml);
    }

    /**
     * 订单查询（QueryStatusRQ）
     */
    public function query(array $data): array
    {
        $xml = $this->buildXml('QueryStatusRQ', $data);
        return $this->request($xml);
    }

    /**
     * 动态订阅（SubscribeRoomStatusRQ）
     */
    public function subscribeRoomStatus(array $data): array
    {
        $xml = $this->buildXml('SubscribeRoomStatusRQ', $data);
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


        $auth->addChild('Password', $credentials['password']);
        
        // 递归添加其他数据
        $this->addXmlChildren($xml, $data);
        
        return $xml->asXML();
    }

    /**
     * 递归添加XML子元素（支持复杂嵌套结构）
     */
    protected function addXmlChildren(\SimpleXMLElement $xml, array $data): void
    {
        foreach ($data as $key => $value) {
            // 跳过 null 值
            if ($value === null) {
                continue;
            }
            
            if (is_array($value)) {
                // 判断是否为索引数组（列表）
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // 索引数组：创建多个同名子元素
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $child = $xml->addChild($key);
                            $this->addXmlChildren($child, $item);
                        } else {
                            $xml->addChild($key, htmlspecialchars((string)$item, ENT_XML1, 'UTF-8'));
                        }
                    }
                } else {
                    // 关联数组：创建单个子元素
                    $child = $xml->addChild($key);
                    $this->addXmlChildren($child, $value);
                }
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
            }
        }
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
        // 记录原始数据（用于调试）
        Log::info('横店下单原始数据', [
            'data' => $data,
            'OrderGuests' => $data['OrderGuests'] ?? null,
            'OrderGuests_is_null' => is_null($data['OrderGuests'] ?? null),
            'OrderGuests_is_array' => is_array($data['OrderGuests'] ?? null),
            'OrderGuests_structure' => $data['OrderGuests'] ?? null,
        ]);
        
        $xml = $this->buildXml('BookRQ', $data);
        
        // 验证 XML 中是否包含 OrderGuests 节点
        $hasOrderGuests = strpos($xml, '<OrderGuests>') !== false;
        $orderGuestsContent = '';
        $orderGuestCount = 0;
        
        if ($hasOrderGuests) {
            // 提取 OrderGuests 节点的内容
            preg_match('/<OrderGuests>(.*?)<\/OrderGuests>/s', $xml, $matches);
            $orderGuestsContent = $matches[1] ?? '';
            
            // 统计 OrderGuest 节点数量
            $orderGuestCount = substr_count($orderGuestsContent, '<OrderGuest>');
        }
        
        // 记录发送到景区方系统的 XML 数据
        Log::info('发送到景区方系统的 XML 请求', [
            'api' => 'BookRQ',
            'url' => $this->config->api_url,
            'xml' => $xml,
            'xml_length' => strlen($xml),
            'has_order_guests' => $hasOrderGuests,
            'order_guest_count' => $orderGuestCount,
            'order_guests_content' => $orderGuestsContent,
        ]);
        
        return $this->request($xml);
    }

    /**
     * 订单查询（QueryStatusRQ）
     */
    public function query(array $data): array
    {
        $xml = $this->buildXml('QueryStatusRQ', $data);
        return $this->request($xml);
    }

    /**
     * 动态订阅（SubscribeRoomStatusRQ）
     */
    public function subscribeRoomStatus(array $data): array
    {
        $xml = $this->buildXml('SubscribeRoomStatusRQ', $data);
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
