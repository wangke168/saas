<?php

namespace App\Jobs;

use App\Models\Price;
use App\Models\Product;
use App\Services\Resource\HengdianService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncHengdianPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Product $product,
        public string $date
    ) {}

    /**
     * Execute the job.
     */
    public function handle(HengdianService $hengdianService): void
    {
        // 同步横店价格
        // 具体实现根据横店接口文档
    }
}
