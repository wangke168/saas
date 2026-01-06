<?php

namespace App\Console\Commands\Pkg;

use App\Enums\OtaPlatform;
use App\Jobs\Pkg\SyncPkgProductPriceJob;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\Pkg\PkgProduct;
use Illuminate\Console\Command;

/**
 * 批量推送打包产品价格到OTA
 */
class SyncPkgProductPriceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pkg:sync-price 
                            {--platform= : OTA平台ID（可选，默认推送所有平台）}
                            {--product= : 打包产品ID（可选，默认推送所有上架产品）}
                            {--dates= : 指定日期范围，格式：YYYY-MM-DD,YYYY-MM-DD（可选，默认推送未来60天）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '批量推送打包产品价格到OTA平台';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('开始推送打包产品价格到OTA...');

        // 解析平台ID
        $platformId = $this->option('platform') ? (int)$this->option('platform') : null;
        if ($platformId) {
            $platform = OtaPlatformModel::find($platformId);
            if (!$platform) {
                $this->error("平台ID {$platformId} 不存在");
                return Command::FAILURE;
            }
            $platforms = collect([$platform]);
        } else {
            // 默认推送所有支持的平台（携程、美团）
            $platforms = OtaPlatformModel::whereIn('code', [
                OtaPlatform::CTRIP->value,
                OtaPlatform::MEITUAN->value,
            ])->get();
        }

        if ($platforms->isEmpty()) {
            $this->error('没有找到支持的OTA平台');
            return Command::FAILURE;
        }

        // 解析产品ID
        $productId = $this->option('product') ? (int)$this->option('product') : null;
        if ($productId) {
            $product = PkgProduct::find($productId);
            if (!$product) {
                $this->error("打包产品ID {$productId} 不存在");
                return Command::FAILURE;
            }
            $products = collect([$product]);
        } else {
            // 默认推送所有上架产品（status = 1）
            $products = PkgProduct::where('status', 1)->get();
        }

        if ($products->isEmpty()) {
            $this->warn('没有找到需要推送的打包产品');
            return Command::SUCCESS;
        }

        // 解析日期范围
        $dates = null;
        if ($this->option('dates')) {
            $dateParts = explode(',', $this->option('dates'));
            if (count($dateParts) === 2) {
                $startDate = trim($dateParts[0]);
                $endDate = trim($dateParts[1]);
                
                // 生成日期数组
                $dates = [];
                $start = \Carbon\Carbon::parse($startDate);
                $end = \Carbon\Carbon::parse($endDate);
                while ($start->lte($end)) {
                    $dates[] = $start->format('Y-m-d');
                    $start->addDay();
                }
            } else {
                $this->error('日期格式错误，请使用格式：YYYY-MM-DD,YYYY-MM-DD');
                return Command::FAILURE;
            }
        }

        $totalJobs = 0;

        // 为每个产品和平台的组合创建推送任务
        foreach ($products as $product) {
            $this->info("处理产品: {$product->product_code} ({$product->product_name})");

            // 获取产品的所有酒店房型关联
            $hotelRoomTypes = $product->hotelRoomTypes;

            if ($hotelRoomTypes->isEmpty()) {
                $this->warn("  产品 {$product->product_code} 没有关联的酒店房型，跳过");
                continue;
            }

            foreach ($platforms as $platform) {
                $this->info("  平台: {$platform->name} ({$platform->code->value})");

                foreach ($hotelRoomTypes as $hotelRoomType) {
                    // 创建推送任务
                    SyncPkgProductPriceJob::dispatch(
                        $product->id,
                        $hotelRoomType->hotel_id,
                        $hotelRoomType->room_type_id,
                        $platform->id,
                        $dates
                    )->onQueue('ota-sync');

                    $totalJobs++;
                }
            }
        }

        $this->info("已创建 {$totalJobs} 个价格推送任务");

        return Command::SUCCESS;
    }
}
