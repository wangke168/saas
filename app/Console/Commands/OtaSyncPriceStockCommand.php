<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OtaPlatform;
use App\Jobs\SyncOtaPriceStockJob;
use App\Models\OtaProduct;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OtaSyncPriceStockCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ota:sync-price-stock {--product= : 指定产品ID} {--platform= : 指定OTA平台ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '同步OTA平台价格和库存（每半小时执行一次）';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('开始同步OTA价格和库存...');

        // 获取携程平台
        $ctripPlatform = OtaPlatformModel::where('code', OtaPlatform::CTRIP->value)->first();
        if (!$ctripPlatform) {
            $this->error('携程平台不存在');
            return Command::FAILURE;
        }

        $platformId = $this->option('platform') ?: $ctripPlatform->id;

        // 获取需要同步的产品
        $query = OtaProduct::where('ota_platform_id', $platformId)
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
                    $platformId,
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
        // 获取产品的所有价格记录（包含房型信息）
        $prices = $product->prices()
            ->with(['roomType.hotel'])
            ->get();

        $combos = [];
        $seen = [];

        foreach ($prices as $price) {
            $roomType = $price->roomType;
            if (!$roomType) {
                continue;
            }

            $hotel = $roomType->hotel;
            if (!$hotel) {
                continue;
            }

            // 检查编码是否存在
            if (empty($product->code) || empty($hotel->code) || empty($roomType->code)) {
                continue;
            }

            // 生成唯一键
            $key = "{$product->id}_{$hotel->id}_{$roomType->id}";

            if (!isset($seen[$key])) {
                $combos[] = [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ];
                $seen[$key] = true;
            }
        }

        return $combos;
    }
}
