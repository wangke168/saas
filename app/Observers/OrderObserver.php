<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\ExternalOrder\ExternalOrderPushDispatcher;

class OrderObserver
{
    public function __construct(
        private readonly ExternalOrderPushDispatcher $dispatcher,
    ) {}

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $routeStatus = $this->dispatcher->mapOrderStatusToRouteStatus($order->status);
        if ($routeStatus === null) {
            return;
        }

        $this->dispatcher->dispatchOrderStatusUpdate($order, $routeStatus);
    }
}
