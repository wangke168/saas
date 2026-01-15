<?php

namespace App\Services\Resource;

use App\Models\Order;
use App\Models\ResourceConfig;
use App\Models\SoftwareProvider;
use App\Services\Resource\HengdianService;
use App\Services\Resource\ZiwoyouService;
use App\Services\ZiwoyouProductMappingService;
use Illuminate\Support\Facades\Log;

/**
 * 资源方服务工厂
 * 根据订单关联的景区，获取对应的资源方服务
 */
class ResourceServiceFactory
{
    /**
     * 根据订单获取对应的资源方服务
     * 
     * @param Order $order 订单
     * @param string $operation 操作类型：'order'（订单操作）、'inventory'（库存操作）
     * @return ResourceServiceInterface|null 资源方服务，如果非系统直连则返回 null
     */
    public static function getService(Order $order, string $operation = 'order'): ?ResourceServiceInterface
    {
        // 重新查询产品并预加载所需的关系（避免队列序列化导致的关系丢失）
        $product = \App\Models\Product::with(['softwareProvider', 'scenicSpot'])
            ->find($order->product_id);
        
        if (!$product) {
            Log::warning('ResourceServiceFactory: 产品不存在', [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ]);
            return null;
        }

        // 获取产品的服务商（必填）- 关系已预加载
        $softwareProvider = $product->softwareProvider;
        if (!$softwareProvider) {
            Log::error('ResourceServiceFactory: 产品未配置服务商', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
            ]);
            throw new \Exception('产品未配置服务商，无法处理订单');
        }

        // 获取景区 - 关系已预加载
        $scenicSpot = $product->scenicSpot;
        if (!$scenicSpot) {
            Log::warning('ResourceServiceFactory: 景区不存在', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'scenic_spot_id' => $product->scenic_spot_id,
            ]);
            return null;
        }

        // 获取对应服务商的资源配置，并预加载服务商关系（用于获取api_url）
        $config = ResourceConfig::with('softwareProvider')
            ->where('scenic_spot_id', $scenicSpot->id)
            ->where('software_provider_id', $softwareProvider->id)
            ->first();
            
        if (!$config) {
            Log::error('ResourceServiceFactory: 景区未配置该服务商的参数', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'scenic_spot_id' => $scenicSpot->id,
                'software_provider_id' => $softwareProvider->id,
                'software_provider_name' => $softwareProvider->name,
            ]);
            throw new \Exception('景区未配置该服务商的参数，无法处理订单');
        }
        
        // 验证 api_url 是否存在
        if (empty($config->api_url)) {
            Log::error('ResourceServiceFactory: 服务商API地址未配置', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'scenic_spot_id' => $scenicSpot->id,
                'software_provider_id' => $softwareProvider->id,
                'software_provider_name' => $softwareProvider->name,
                'has_software_provider' => $config->softwareProvider !== null,
                'software_provider_api_url' => $config->softwareProvider?->api_url,
            ]);
            throw new \Exception('服务商API地址未配置，无法处理订单。请在软件服务商管理页面配置API地址。');
        }

        // 根据操作类型判断是否系统直连（方案A）
        $syncMode = $config->extra_config['sync_mode'] ?? [];
        
        if ($operation === 'order') {
            // 订单操作：检查 order 配置
            $orderMode = $syncMode['order'] ?? 'manual';
            if ($orderMode === 'manual' || $orderMode === 'other') {
                Log::info('ResourceServiceFactory: 订单处理方式不是系统直连', [
                    'order_id' => $order->id,
                    'order_mode' => $orderMode,
                    'scenic_spot_id' => $scenicSpot->id,
                ]);
                return null; // 不直连，返回null，走手工流程或其他系统
            }
            
            // 订单下发服务商分离：如果配置了 order_provider，使用指定的服务商
            // 注意：order_provider 存储的是服务商ID，需要通过ID查找服务商
            $orderProviderId = $config->extra_config['order_provider'] ?? null;
            if ($orderProviderId) {
                // 通过ID查找订单下发服务商
                $orderSoftwareProvider = SoftwareProvider::find($orderProviderId);
                if (!$orderSoftwareProvider) {
                    Log::warning('ResourceServiceFactory: 订单下发服务商不存在', [
                        'order_id' => $order->id,
                        'order_provider_id' => $orderProviderId,
                    ]);
                } elseif ($orderSoftwareProvider->api_type !== $softwareProvider->api_type) {
                    // 只有订单下发服务商与产品服务商不同时，才切换
                    Log::info('ResourceServiceFactory: 检测到订单下发服务商分离配置', [
                        'order_id' => $order->id,
                        'inventory_provider' => $softwareProvider->api_type,
                        'order_provider' => $orderSoftwareProvider->api_type,
                        'order_provider_id' => $orderProviderId,
                    ]);
                    
                    // 查找订单下发服务商的配置
                    $orderConfig = ResourceConfig::with('softwareProvider')
                        ->where('scenic_spot_id', $scenicSpot->id)
                        ->where('software_provider_id', $orderSoftwareProvider->id)
                        ->first();
                    
                    if ($orderConfig && !empty($orderConfig->api_url)) {
                        Log::info('ResourceServiceFactory: 使用订单下发服务商配置', [
                            'order_id' => $order->id,
                            'order_provider' => $orderSoftwareProvider->api_type,
                            'order_provider_id' => $orderProviderId,
                            'order_config_id' => $orderConfig->id,
                        ]);
                        
                        $softwareProvider = $orderSoftwareProvider;
                        $config = $orderConfig;
                    } else {
                        Log::warning('ResourceServiceFactory: 订单下发服务商配置不存在或API地址未配置，使用原服务商', [
                            'order_id' => $order->id,
                            'order_provider' => $orderSoftwareProvider->api_type,
                            'order_provider_id' => $orderProviderId,
                            'has_config' => $orderConfig !== null,
                            'has_api_url' => $orderConfig?->api_url ?? false,
                        ]);
                    }
                }
                
                // 产品级别控制：如果订单下发服务商是自我游，检查产品是否有映射关系
                if ($softwareProvider->api_type === 'ziwoyou') {
                    $mappingService = app(ZiwoyouProductMappingService::class);
                    $hasMapping = $mappingService->hasMapping(
                        $order->product_id,
                        $order->hotel_id,
                        $order->room_type_id
                    );
                    
                    if (!$hasMapping) {
                        Log::info('ResourceServiceFactory: 产品没有自我游映射关系，走手工流程', [
                            'order_id' => $order->id,
                            'product_id' => $order->product_id,
                            'hotel_id' => $order->hotel_id,
                            'room_type_id' => $order->room_type_id,
                            'scenic_spot_id' => $scenicSpot->id,
                        ]);
                        return null; // 没有映射关系，返回null，走手工流程
                    }
                    
                    Log::info('ResourceServiceFactory: 产品有自我游映射关系，使用自我游服务', [
                        'order_id' => $order->id,
                        'product_id' => $order->product_id,
                        'hotel_id' => $order->hotel_id,
                        'room_type_id' => $order->room_type_id,
                    ]);
                }
            }
            // $orderMode === 'auto' 时，继续执行，返回资源方服务
        } elseif ($operation === 'inventory') {
            // 库存操作：检查 inventory 配置
            $inventoryMode = $syncMode['inventory'] ?? 'manual';
            if ($inventoryMode !== 'push') {
                Log::info('ResourceServiceFactory: 库存同步方式不是推送', [
                    'order_id' => $order->id,
                    'inventory_mode' => $inventoryMode,
                    'scenic_spot_id' => $scenicSpot->id,
                ]);
                return null;
            }
        }

        // 根据软件服务商类型返回对应的服务，并设置配置
        // 注意：$softwareProvider 可能已经被订单下发服务商分离逻辑修改
        $service = match($softwareProvider->api_type) {
            'hengdian' => app(HengdianService::class)->setConfig($config),
            'ziwoyou' => app(ZiwoyouService::class)->setConfig($config),
            default => null,
        };

        if (!$service) {
            Log::warning('ResourceServiceFactory: 软件服务商类型不匹配', [
                'order_id' => $order->id,
                'api_type' => $softwareProvider->api_type,
                'expected' => 'hengdian',
                'software_provider_id' => $softwareProvider->id,
            ]);
        }

        Log::info('ResourceServiceFactory: 服务创建成功', [
            'order_id' => $order->id,
            'api_type' => $softwareProvider->api_type,
            'config_id' => $config->id,
            'scenic_spot_id' => $config->scenic_spot_id,
            'software_provider_id' => $config->software_provider_id,
        ]);

        return $service;
    }

    /**
     * 检查订单是否支持系统直连操作
     * 
     * @param Order $order 订单
     * @param string $operation 操作类型：'order'（订单操作）、'inventory'（库存操作）
     * @return bool
     */
    public static function isSystemConnected(Order $order, string $operation = 'order'): bool
    {
        // 重新查询产品并预加载所需的关系（避免队列序列化导致的关系丢失）
        $product = \App\Models\Product::with(['softwareProvider', 'scenicSpot'])
            ->find($order->product_id);
        
        if (!$product) {
            Log::info('ResourceServiceFactory::isSystemConnected: 产品不存在', [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'operation' => $operation,
            ]);
            return false;
        }

        // 获取产品的服务商（必填）- 关系已预加载
        $softwareProvider = $product->softwareProvider;
        if (!$softwareProvider) {
            Log::info('ResourceServiceFactory::isSystemConnected: 产品未配置服务商', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'operation' => $operation,
            ]);
            return false;
        }

        // 获取景区
        $scenicSpot = $product->scenicSpot;
        if (!$scenicSpot) {
            Log::info('ResourceServiceFactory::isSystemConnected: 景区不存在', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'scenic_spot_id' => $product->scenic_spot_id,
                'operation' => $operation,
            ]);
            return false;
        }

        // 获取对应服务商的资源配置，并预加载服务商关系（用于获取api_url）
        $config = ResourceConfig::with('softwareProvider')
            ->where('scenic_spot_id', $scenicSpot->id)
            ->where('software_provider_id', $softwareProvider->id)
            ->first();
            
        if (!$config) {
            Log::info('ResourceServiceFactory::isSystemConnected: 景区未配置该服务商的参数', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'scenic_spot_id' => $scenicSpot->id,
                'software_provider_id' => $softwareProvider->id,
                'operation' => $operation,
            ]);
            return false;
        }

        $syncMode = $config->extra_config['sync_mode'] ?? [];
        
        if ($operation === 'order') {
            $orderMode = $syncMode['order'] ?? 'manual';
            $isConnected = $orderMode === 'auto';
            
            Log::info('ResourceServiceFactory::isSystemConnected: 订单操作检查结果', [
                'order_id' => $order->id,
                'scenic_spot_id' => $scenicSpot->id,
                'order_mode' => $orderMode,
                'is_connected' => $isConnected,
                'sync_mode' => $syncMode,
            ]);
            
            return $isConnected;
        } elseif ($operation === 'inventory') {
            $inventoryMode = $syncMode['inventory'] ?? 'manual';
            $isConnected = $inventoryMode === 'push';
            
            Log::info('ResourceServiceFactory::isSystemConnected: 库存操作检查结果', [
                'order_id' => $order->id,
                'scenic_spot_id' => $scenicSpot->id,
                'inventory_mode' => $inventoryMode,
                'is_connected' => $isConnected,
                'sync_mode' => $syncMode,
            ]);
            
            return $isConnected;
        }

        Log::info('ResourceServiceFactory::isSystemConnected: 未知操作类型', [
            'order_id' => $order->id,
            'operation' => $operation,
        ]);

        return false;
    }
}
