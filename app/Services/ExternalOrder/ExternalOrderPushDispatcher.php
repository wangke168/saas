<?php

namespace App\Services\ExternalOrder;

use App\Enums\OrderStatus;
use App\Enums\PkgOrderStatus;
use App\Jobs\PushExternalOrderJob;
use App\Models\Order;
use App\Models\OrderExternalPushLog;
use App\Models\Pkg\PkgOrder;
use App\Services\Presale\PresaleOtaConsumeService;
use App\Support\ExternalOrder\ExternalOrderRoute;
use Illuminate\Support\Facades\Log;

class ExternalOrderPushDispatcher
{
    public function dispatchOrderPaid(Order $order): void
    {
        if (PresaleOtaConsumeService::isPresaleParentOrder($order)) {
            return;
        }

        $order->loadMissing(['otaPlatform', 'product', 'hotel']);

        PushExternalOrderJob::dispatch(
            OrderExternalPushLog::ORDER_TYPE_ORDER,
            $order->id,
            OrderExternalPushLog::PUSH_TYPE_CREATE,
            ExternalOrderRoute::STATUS_PENDING,
        );

        if ($order->status === OrderStatus::CONFIRMED) {
            $this->dispatchOrderStatusUpdate($order, ExternalOrderRoute::STATUS_CONFIRMED);
        }
    }

    public function dispatchOrderStatusUpdate(Order $order, int $routeOrderStatus): void
    {
        if (PresaleOtaConsumeService::isPresaleParentOrder($order)) {
            return;
        }

        if (! in_array($routeOrderStatus, [
            ExternalOrderRoute::STATUS_CONFIRMED,
            ExternalOrderRoute::STATUS_VERIFIED,
            ExternalOrderRoute::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        PushExternalOrderJob::dispatch(
            OrderExternalPushLog::ORDER_TYPE_ORDER,
            $order->id,
            OrderExternalPushLog::PUSH_TYPE_STATUS_UPDATE,
            $routeOrderStatus,
        );
    }

    public function dispatchPkgOrderPaid(PkgOrder $pkgOrder): void
    {
        PushExternalOrderJob::dispatch(
            OrderExternalPushLog::ORDER_TYPE_PKG_ORDER,
            $pkgOrder->id,
            OrderExternalPushLog::PUSH_TYPE_CREATE,
            ExternalOrderRoute::STATUS_PENDING,
        );

        if ($pkgOrder->status === PkgOrderStatus::CONFIRMED) {
            $this->dispatchPkgOrderStatusUpdate($pkgOrder, ExternalOrderRoute::STATUS_CONFIRMED);
        }
    }

    public function dispatchPkgOrderStatusUpdate(PkgOrder $pkgOrder, int $routeOrderStatus): void
    {
        if (! in_array($routeOrderStatus, [
            ExternalOrderRoute::STATUS_CONFIRMED,
            ExternalOrderRoute::STATUS_VERIFIED,
            ExternalOrderRoute::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        PushExternalOrderJob::dispatch(
            OrderExternalPushLog::ORDER_TYPE_PKG_ORDER,
            $pkgOrder->id,
            OrderExternalPushLog::PUSH_TYPE_STATUS_UPDATE,
            $routeOrderStatus,
        );
    }

    public function mapOrderStatusToRouteStatus(OrderStatus $status): ?int
    {
        return match ($status) {
            OrderStatus::CONFIRMED => ExternalOrderRoute::STATUS_CONFIRMED,
            OrderStatus::VERIFIED => ExternalOrderRoute::STATUS_VERIFIED,
            OrderStatus::CANCEL_APPROVED => ExternalOrderRoute::STATUS_CANCELLED,
            default => null,
        };
    }

    public function mapPkgOrderStatusToRouteStatus(PkgOrderStatus $status): ?int
    {
        return match ($status) {
            PkgOrderStatus::CONFIRMED => ExternalOrderRoute::STATUS_CONFIRMED,
            PkgOrderStatus::VERIFIED => ExternalOrderRoute::STATUS_VERIFIED,
            PkgOrderStatus::CANCELLED => ExternalOrderRoute::STATUS_CANCELLED,
            default => null,
        };
    }

    public function resolveScenicSpotIdForOrder(Order $order): ?int
    {
        $order->loadMissing(['product', 'hotel.scenicSpot']);

        return $order->product?->scenic_spot_id
            ?? $order->hotel?->scenicSpot?->id;
    }

    public function resolveScenicSpotIdForPkgOrder(PkgOrder $pkgOrder): ?int
    {
        $pkgOrder->loadMissing(['hotel']);

        return $pkgOrder->hotel?->scenic_spot_id;
    }

    public function logSkipped(string $message, array $context = []): void
    {
        Log::info('ExternalOrderPushDispatcher: '.$message, $context);
    }
}
