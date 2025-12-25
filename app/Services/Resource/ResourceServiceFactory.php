<?php

namespace App\Services\Resource;

use App\Models\Order;
use App\Services\Resource\HengdianService;

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
     * @return ResourceServiceInterface|null 资源方服务，如果非系统直连则返回 null
     */
    public static function getService(Order $order): ?ResourceServiceInterface
    {
        // 获取订单关联的景区
        $scenicSpot = $order->hotel->scenicSpot ?? null;
        
        if (!$scenicSpot) {
            return null;
        }

        // 检查是否系统直连
        if (!$scenicSpot->is_system_connected) {
            return null; // 非系统直连
        }

        // 获取软件服务商
        $softwareProvider = $scenicSpot->softwareProvider;
        if (!$softwareProvider) {
            return null;
        }

        // 根据软件服务商类型返回对应的服务
        return match($softwareProvider->api_type) {
            'hengdian' => app(HengdianService::class),
            // 未来可以扩展其他资源方
            default => null,
        };
    }

    /**
     * 检查订单是否支持系统直连操作
     * 
     * @param Order $order 订单
     * @return bool
     */
    public static function isSystemConnected(Order $order): bool
    {
        $scenicSpot = $order->hotel->scenicSpot ?? null;
        
        return $scenicSpot && $scenicSpot->is_system_connected;
    }
}

