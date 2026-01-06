<?php

namespace App\Jobs\Pkg;

use App\Services\Pkg\PkgProductPriceService;
use App\Models\Pkg\PkgProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdatePkgProductPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;
    
    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 300; // 5分钟
    
    /**
     * @param int $pkgProductId 打包产品ID
     */
    public function __construct(
        public int $pkgProductId
    ) {}
    
    /**
     * 执行任务：更新价格日历（只更新未来60天）
     */
    public function handle(PkgProductPriceService $priceService): void
    {
        try {
            $product = PkgProduct::find($this->pkgProductId);
            if (!$product) {
                Log::warning('UpdatePkgProductPriceJob: 产品不存在', [
                    'pkg_product_id' => $this->pkgProductId,
                ]);
                return;
            }
            
            Log::info('UpdatePkgProductPriceJob: 开始更新价格日历', [
                'pkg_product_id' => $this->pkgProductId,
                'product_code' => $product->product_code,
            ]);
            
            // 计算价格（未来60天）
            $priceService->calculateDailyPrices($product);
            
            Log::info('UpdatePkgProductPriceJob: 价格日历更新完成', [
                'pkg_product_id' => $this->pkgProductId,
            ]);
            
        } catch (\Exception $e) {
            Log::error('UpdatePkgProductPriceJob: 更新失败', [
                'pkg_product_id' => $this->pkgProductId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e; // 触发重试
        }
    }
}
