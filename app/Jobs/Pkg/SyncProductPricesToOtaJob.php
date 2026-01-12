<?php

namespace App\Jobs\Pkg;

use App\Models\Pkg\PkgProduct;
use App\Services\Pkg\PkgProductOtaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 打包产品OTA价格推送任务
 * 
 * 异步执行价格推送到OTA平台
 */
class SyncProductPricesToOtaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 600; // 10分钟

    /**
     * @param int $pkgProductId 打包产品ID
     * @param string $otaPlatformCode OTA平台编码（ctrip, meituan等）
     * @param array|null $dates 指定日期数组，如果为null则推送未来60天
     * @param int|null $pkgOtaProductId 打包产品OTA绑定ID（可选，如果提供则在推送成功后更新状态）
     */
    public function __construct(
        public int $pkgProductId,
        public string $otaPlatformCode,
        public ?array $dates = null,
        public ?int $pkgOtaProductId = null
    ) {}

    /**
     * 执行任务
     */
    public function handle(PkgProductOtaSyncService $syncService): void
    {
        try {
            $product = PkgProduct::find($this->pkgProductId);
            
            if (!$product) {
                Log::warning('SyncProductPricesToOtaJob: 打包产品不存在', [
                    'pkg_product_id' => $this->pkgProductId,
                ]);
                return;
            }

            Log::info('SyncProductPricesToOtaJob: 开始推送价格到OTA', [
                'pkg_product_id' => $this->pkgProductId,
                'product_code' => $product->product_code,
                'platform' => $this->otaPlatformCode,
            ]);

            $result = $syncService->syncProductPricesToOta(
                $product,
                $this->otaPlatformCode,
                $this->dates
            );

            if ($result['success']) {
                Log::info('SyncProductPricesToOtaJob: 价格推送成功', [
                    'pkg_product_id' => $this->pkgProductId,
                    'platform' => $this->otaPlatformCode,
                    'summary' => $result['summary'] ?? [],
                ]);

                // 如果提供了 pkgOtaProductId，更新绑定记录状态
                if ($this->pkgOtaProductId) {
                    $pkgOtaProduct = \App\Models\Pkg\PkgOtaProduct::find($this->pkgOtaProductId);
                    if ($pkgOtaProduct) {
                        $pkgOtaProduct->update([
                            'is_active' => true,
                            'pushed_at' => now(),
                            'push_status' => 'success',
                            'push_completed_at' => now(),
                            'push_message' => $result['message'] ?? '推送成功',
                        ]);
                    }
                }
            } else {
                Log::warning('SyncProductPricesToOtaJob: 价格推送失败', [
                    'pkg_product_id' => $this->pkgProductId,
                    'platform' => $this->otaPlatformCode,
                    'message' => $result['message'],
                ]);

                // 如果提供了 pkgOtaProductId，更新绑定记录状态
                if ($this->pkgOtaProductId) {
                    $pkgOtaProduct = \App\Models\Pkg\PkgOtaProduct::find($this->pkgOtaProductId);
                    if ($pkgOtaProduct) {
                        $pkgOtaProduct->update([
                            'push_status' => 'failed',
                            'push_completed_at' => now(),
                            'push_message' => $result['message'] ?? '推送失败',
                        ]);
                    }
                }
                
                // 如果失败，抛出异常以触发重试
                throw new \Exception($result['message']);
            }

        } catch (\Exception $e) {
            // 如果提供了 pkgOtaProductId，更新绑定记录状态为失败
            if ($this->pkgOtaProductId) {
                $pkgOtaProduct = \App\Models\Pkg\PkgOtaProduct::find($this->pkgOtaProductId);
                if ($pkgOtaProduct) {
                    $pkgOtaProduct->update([
                        'push_status' => 'failed',
                        'push_completed_at' => now(),
                        'push_message' => $e->getMessage(),
                    ]);
                }
            }

            Log::error('SyncProductPricesToOtaJob: 推送失败', [
                'pkg_product_id' => $this->pkgProductId,
                'platform' => $this->otaPlatformCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // 触发重试
        }
    }

    /**
     * 任务失败时的处理
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncProductPricesToOtaJob: 任务最终失败', [
            'pkg_product_id' => $this->pkgProductId,
            'platform' => $this->otaPlatformCode,
            'error' => $exception->getMessage(),
        ]);

        // 如果提供了 pkgOtaProductId，更新绑定记录状态为失败
        if ($this->pkgOtaProductId) {
            $pkgOtaProduct = \App\Models\Pkg\PkgOtaProduct::find($this->pkgOtaProductId);
            if ($pkgOtaProduct) {
                $pkgOtaProduct->update([
                    'push_status' => 'failed',
                    'push_completed_at' => now(),
                    'push_message' => $exception->getMessage(),
                ]);
            }
        }
    }
}

