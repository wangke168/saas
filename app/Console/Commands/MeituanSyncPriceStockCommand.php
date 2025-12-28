<?php

namespace App\Console\Commands;

use App\Enums\OtaPlatform;
use App\Jobs\SyncOtaPriceStockJob;
use App\Models\OtaProduct;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\Product;
use Illuminate\Console\Command;

class MeituanSyncPriceStockCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meituan:sync-price-stock {--product= : 指定产品ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '同步美团多层价格日历变化通知V2（每半小时执行一次）';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('开始同步美团价格和库存...');

        // 获取美团平台
        $meituanPlatform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
        if (!$meituanPlatform) {
            $this->error('美团平台不存在');
            return Command::FAILURE;
        }

        // 获取需要同步的产品
        $query = OtaProduct::where('ota_platform_id', $meituanPlatform->id)
            ->where('is_active', true)
            ->with(['product' => function ($q) {
                $q->where('is_active', true);
            }]);

        if ($this->option('product')) {
            $query->where('product_id', $this->option('product'));
        }

        $otaProducts = $query->get();

        if ($otaProducts->isEmpty()) {
            $this->info('没有需要同步的产品');
            return Command::SUCCESS;
        }

        $totalJobs = 0;

        foreach ($otaProducts as $otaProduct) {
            $product = $otaProduct->product;
            if (!$product || !$product->is_active) {
                continue;
            }

            // 获取产品的所有"产品-酒店-房型"组合
            $combos = $this->getProductCombos($product);

            if (empty($combos)) {
                $this->warn("产品 {$product->id} ({$product->name}) 没有关联的酒店和房型");
                continue;
            }

            // 为每个组合创建同步任务
            foreach ($combos as $combo) {
                SyncOtaPriceStockJob::dispatch(
                    $product->id,
                    $combo['hotel_id'],
                    $combo['room_type_id'],
                    $meituanPlatform->id,
                    true, // syncPrice
                    true  // syncStock
                )->onQueue('ota-sync');

                $totalJobs++;
            }
        }

        $this->info("已创建 {$totalJobs} 个同步任务");

        return Command::SUCCESS;
    }

    /**
     * 获取产品的所有"产品-酒店-房型"组合
     */
    protected function getProductCombos(Product $product): array
    {
        $prices = $product->prices()
            ->with(['roomType.hotel'])
            ->get();

        $combos = [];
        $seen = [];

        foreach ($prices as $price) {
            $roomType = $price->roomType;
            $hotel = $roomType->hotel ?? null;

            if (!$hotel || !$roomType) {
                continue;
            }

            // 检查编码
            if (empty($hotel->code) || empty($roomType->code)) {
                continue;
            }

            $key = "{$product->id}_{$hotel->id}_{$roomType->id}";
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $combos[] = [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'hotel' => $hotel,
                'room_type' => $roomType,
            ];
        }

        return $combos;
    }
}
