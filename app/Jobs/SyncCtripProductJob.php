<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\OTA\CtripService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncCtripProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Product $product
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CtripService $ctripService): void
    {
        // 同步产品价格和库存到携程
        // 具体实现根据携程API文档
    }
}
