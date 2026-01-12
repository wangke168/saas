<?php

namespace App\Jobs;

use App\Models\SalesProduct;
use App\Services\SystemPkgPriceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 更新系统打包产品价格日历任务
 * 只更新未来60天
 */
class UpdateSystemPkgPriceJob implements ShouldQueue
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
     * @param int $salesProductId 销售产品ID
     */
    public function __construct(
        public int $salesProductId
    ) {}

    /**
     * 执行任务：更新价格日历（只更新未来60天）
     */
    public function handle(SystemPkgPriceService $priceService): void
    {
        try {
            $salesProduct = SalesProduct::find($this->salesProductId);
            if (!$salesProduct) {
                Log::warning('UpdateSystemPkgPriceJob: 产品不存在', [
                    'sales_product_id' => $this->salesProductId,
                ]);
                return;
            }

            Log::info('UpdateSystemPkgPriceJob: 开始更新价格日历', [
                'sales_product_id' => $this->salesProductId,
                'ota_product_code' => $salesProduct->ota_product_code,
            ]);

            // 更新价格日历（只更新未来60天）
            $priceService->updatePriceCalendar($salesProduct);

            Log::info('UpdateSystemPkgPriceJob: 价格日历更新完成', [
                'sales_product_id' => $this->salesProductId,
            ]);
        } catch (\Exception $e) {
            Log::error('UpdateSystemPkgPriceJob: 更新失败', [
                'sales_product_id' => $this->salesProductId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // 触发重试
        }
    }
}


