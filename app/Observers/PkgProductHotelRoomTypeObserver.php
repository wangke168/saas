<?php

namespace App\Observers;

use App\Jobs\Pkg\UpdatePkgProductPriceJob;
use App\Models\Pkg\PkgProductHotelRoomType;

/**
 * 酒店房型关联变更观察者
 * 当产品关联的酒店房型变更时，触发价格更新
 */
class PkgProductHotelRoomTypeObserver
{
    /**
     * 酒店房型关联创建后
     */
    public function created(PkgProductHotelRoomType $hotelRoomType): void
    {
        UpdatePkgProductPriceJob::dispatch($hotelRoomType->pkg_product_id);
    }
    
    /**
     * 酒店房型关联删除后
     */
    public function deleted(PkgProductHotelRoomType $hotelRoomType): void
    {
        UpdatePkgProductPriceJob::dispatch($hotelRoomType->pkg_product_id);
    }
}
