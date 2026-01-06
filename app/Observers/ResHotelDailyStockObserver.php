<?php

namespace App\Observers;

use App\Models\Pkg\PkgProduct;
use App\Jobs\Pkg\UpdatePkgProductPriceJob;
use App\Models\Res\ResHotelDailyStock;

/**
 * 酒店价格变更观察者
 * 当酒店价格变更时，触发相关打包产品的价格更新
 */
class ResHotelDailyStockObserver
{
    /**
     * 酒店价格变更后
     */
    public function saved(ResHotelDailyStock $hotelStock): void
    {
        $this->triggerPriceUpdate($hotelStock->hotel_id, $hotelStock->room_type_id);
    }
    
    /**
     * 酒店价格删除后
     */
    public function deleted(ResHotelDailyStock $hotelStock): void
    {
        $this->triggerPriceUpdate($hotelStock->hotel_id, $hotelStock->room_type_id);
    }
    
    /**
     * 触发价格更新
     */
    private function triggerPriceUpdate(int $hotelId, int $roomTypeId): void
    {
        // 找到所有关联该酒店房型的打包产品
        $pkgProducts = PkgProduct::whereHas('hotelRoomTypes', function ($query) use ($hotelId, $roomTypeId) {
            $query->where('hotel_id', $hotelId)
                  ->where('room_type_id', $roomTypeId);
        })->get();
        
        // 异步更新价格
        foreach ($pkgProducts as $product) {
            UpdatePkgProductPriceJob::dispatch($product->id);
        }
    }
}
