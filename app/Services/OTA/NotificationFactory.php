<?php

namespace App\Services\OTA;

use App\Contracts\OtaNotificationInterface;
use App\Enums\OtaPlatform;
use App\Models\Order;
use App\Services\OTA\Notifications\CtripNotificationService;
use App\Services\OTA\Notifications\MeituanNotificationService;

/**
 * OTA通知服务工厂类
 * 根据订单的OTA平台创建对应的通知服务
 */
class NotificationFactory
{
    /**
     * 根据订单创建对应的通知服务
     * 
     * @param Order $order 订单
     * @return OtaNotificationInterface|null
     */
    public static function create(Order $order): ?OtaNotificationInterface
    {
        $platform = $order->otaPlatform?->code;

        return match($platform) {
            OtaPlatform::CTRIP => app(CtripNotificationService::class),
            OtaPlatform::MEITUAN => app(MeituanNotificationService::class),
            default => null,
        };
    }
}

