<?php

namespace App\Services\Resource;

use App\Models\Order;
use App\Services\Resource\HengdianService;
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
        // 获取订单关联的景区
        $scenicSpot = $order->hotel->scenicSpot ?? null;
        
        if (!$scenicSpot) {
            Log::warning('ResourceServiceFactory: 景区不存在', [
                'order_id' => $order->id,
                'hotel_id' => $order->hotel_id,
            ]);
            return null;
        }

        // 获取资源配置
        $config = $scenicSpot->resourceConfig;
        if (!$config) {
            Log::warning('ResourceServiceFactory: 资源配置不存在', [
                'order_id' => $order->id,
                'scenic_spot_id' => $scenicSpot->id,
                'resource_config_id' => $scenicSpot->resource_config_id,
            ]);
            return null;
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

        // 获取软件服务商
        $softwareProvider = $scenicSpot->softwareProvider;
        if (!$softwareProvider) {
            Log::warning('ResourceServiceFactory: 软件服务商不存在', [
                'order_id' => $order->id,
                'scenic_spot_id' => $scenicSpot->id,
                'software_provider_id' => $scenicSpot->software_provider_id,
            ]);
            return null;
        }

        // 根据软件服务商类型返回对应的服务
        $service = match($softwareProvider->api_type) {
            'hengdian' => app(HengdianService::class),
            // 未来可以扩展其他资源方
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
        $scenicSpot = $order->hotel->scenicSpot ?? null;
        
        if (!$scenicSpot) {
            return false;
        }

        $config = $scenicSpot->resourceConfig;
        if (!$config) {
            return false;
        }

        $syncMode = $config->extra_config['sync_mode'] ?? [];
        
        if ($operation === 'order') {
            $orderMode = $syncMode['order'] ?? 'manual';
            return $orderMode === 'auto';
        } elseif ($operation === 'inventory') {
            return ($syncMode['inventory'] ?? 'manual') === 'push';
        }

        return false;
    }
}
