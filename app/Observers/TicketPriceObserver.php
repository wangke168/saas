<?php

namespace App\Observers;

use App\Models\Pkg\PkgProduct;
use App\Jobs\Pkg\UpdatePkgProductPriceJob;
use App\Models\TicketPrice;

/**
 * 门票价格变更观察者
 * 当门票价格变更时，触发相关打包产品的价格更新
 */
class TicketPriceObserver
{
    /**
     * 门票价格变更后
     */
    public function saved(TicketPrice $ticketPrice): void
    {
        $this->triggerPriceUpdate($ticketPrice->ticket_id);
    }
    
    /**
     * 门票价格删除后
     */
    public function deleted(TicketPrice $ticketPrice): void
    {
        $this->triggerPriceUpdate($ticketPrice->ticket_id);
    }
    
    /**
     * 触发价格更新
     */
    private function triggerPriceUpdate(int $ticketId): void
    {
        // 找到所有包含该门票的打包产品
        $pkgProducts = PkgProduct::whereHas('bundleItems', function ($query) use ($ticketId) {
            $query->where('ticket_id', $ticketId);
        })->get();
        
        // 异步更新价格
        foreach ($pkgProducts as $product) {
            UpdatePkgProductPriceJob::dispatch($product->id);
        }
    }
}
