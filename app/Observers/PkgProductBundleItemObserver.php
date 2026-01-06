<?php

namespace App\Observers;

use App\Jobs\Pkg\UpdatePkgProductPriceJob;
use App\Models\Pkg\PkgProductBundleItem;

/**
 * 门票关联变更观察者
 * 当产品关联的门票变更时，触发价格更新
 */
class PkgProductBundleItemObserver
{
    /**
     * 门票关联创建后
     */
    public function created(PkgProductBundleItem $bundleItem): void
    {
        UpdatePkgProductPriceJob::dispatch($bundleItem->pkg_product_id);
    }
    
    /**
     * 门票关联删除后
     */
    public function deleted(PkgProductBundleItem $bundleItem): void
    {
        UpdatePkgProductPriceJob::dispatch($bundleItem->pkg_product_id);
    }
}
