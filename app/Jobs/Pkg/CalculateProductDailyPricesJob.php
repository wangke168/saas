<?php

namespace App\Jobs\Pkg;

use App\Models\Pkg\PkgProduct;
use App\Services\Pkg\PkgProductPriceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateProductDailyPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $productId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PkgProductPriceService $service): void
    {
        try {
            $product = PkgProduct::find($this->productId);

            if (!$product) {
                Log::warning('打包产品不存在，跳过价格计算', ['product_id' => $this->productId]);
                return;
            }

            $service->calculateDailyPrices($product);
        } catch (\Exception $e) {
            Log::error('打包产品价格计算任务失败', [
                'product_id' => $this->productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 任务失败时的处理
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('打包产品价格计算任务最终失败', [
            'product_id' => $this->productId,
            'error' => $exception->getMessage(),
        ]);
    }
}



