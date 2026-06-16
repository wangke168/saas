<?php

namespace App\Observers;

use App\Models\Pkg\PkgOrder;
use App\Services\ExternalOrder\ExternalOrderPushDispatcher;

class PkgOrderObserver
{
    public function __construct(
        private readonly ExternalOrderPushDispatcher $dispatcher,
    ) {}

    public function updated(PkgOrder $pkgOrder): void
    {
        if (! $pkgOrder->wasChanged('status')) {
            return;
        }

        $originalStatus = $pkgOrder->getOriginal('status');
        $newStatus = $pkgOrder->status;

        if ($originalStatus === $newStatus) {
            return;
        }

        $routeStatus = $this->dispatcher->mapPkgOrderStatusToRouteStatus($newStatus);
        if ($routeStatus === null) {
            return;
        }

        $this->dispatcher->dispatchPkgOrderStatusUpdate($pkgOrder, $routeStatus);
    }
}
