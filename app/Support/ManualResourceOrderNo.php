<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ResourceConfig;
use App\Services\Resource\ResourceServiceFactory;

class ManualResourceOrderNo
{
    public static function isPlaceholder(?string $resourceOrderNo): bool
    {
        if ($resourceOrderNo === null || $resourceOrderNo === '') {
            return true;
        }

        return str_starts_with($resourceOrderNo, 'AUTO_MANUAL_');
    }

    public static function resolveResourceConfig(Order $order): ?ResourceConfig
    {
        $order->loadMissing(['product.scenicSpot', 'hotel.scenicSpot']);

        $product = $order->product;
        if ($product === null) {
            return null;
        }

        $scenicSpot = $product->scenicSpot ?? $order->hotel?->scenicSpot;
        if ($scenicSpot === null || $product->software_provider_id === null) {
            return null;
        }

        return ResourceConfig::query()
            ->where('scenic_spot_id', $scenicSpot->id)
            ->where('software_provider_id', $product->software_provider_id)
            ->first();
    }

    public static function requiresInput(Order $order): bool
    {
        if (ResourceServiceFactory::isSystemConnected($order, 'order')) {
            return false;
        }

        $config = self::resolveResourceConfig($order);

        return $config?->requiresResourceOrderNo() ?? false;
    }

    public static function needsResourceOrderNoOnConfirm(Order $order): bool
    {
        return self::requiresInput($order) && self::isPlaceholder($order->resource_order_no);
    }

    public static function canBackfillResourceOrderNo(Order $order): bool
    {
        if (! self::requiresInput($order) || ! self::isPlaceholder($order->resource_order_no)) {
            return false;
        }

        return in_array($order->status, [
            OrderStatus::CONFIRMING,
            OrderStatus::CONFIRMED,
            OrderStatus::VERIFIED,
            OrderStatus::CANCEL_REQUESTED,
            OrderStatus::CANCEL_REJECTED,
        ], true);
    }
}
