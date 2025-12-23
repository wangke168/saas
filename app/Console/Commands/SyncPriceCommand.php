<?php

namespace App\Console\Commands;

use App\Jobs\SyncHengdianPriceJob;
use App\Models\Product;
use Illuminate\Console\Command;

class SyncPriceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:price {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '同步资源方价格';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        
        $this->info("开始同步价格，日期：{$date}");

        // 获取所有价格来源为接口推送的产品
        $products = Product::where('price_source', 'api')->get();

        foreach ($products as $product) {
            SyncHengdianPriceJob::dispatch($product, $date);
        }

        $this->info('价格同步任务已提交到队列');

        return Command::SUCCESS;
    }
}
