<?php

namespace App\Jobs\Pkg;

use App\Enums\OtaPlatform;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\Pkg\PkgProduct;
use App\Services\OTA\PkgProductPriceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 打包产品价格推送队列任务
 */
class SyncPkgProductPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $pkgProductId,
        public int $hotelId,
        public int $roomTypeId,
        public int $otaPlatformId,
        public ?array $dates = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PkgProductPriceService $priceService): void
    {
        try {
            // 加载数据
            $pkgProduct = PkgProduct::find($this->pkgProductId);

            if (!$pkgProduct) {
                Log::warning('打包产品价格推送任务：产品不存在', [
                    'pkg_product_id' => $this->pkgProductId,
                ]);
                return;
            }

            // 检查产品状态（status = 1 表示上架）
            if ($pkgProduct->status !== 1) {
                Log::info('打包产品价格推送任务：产品未上架，跳过', [
                    'pkg_product_id' => $this->pkgProductId,
                    'status' => $pkgProduct->status,
                ]);
                return;
            }

            // 检查平台类型
            $platform = OtaPlatformModel::find($this->otaPlatformId);
            if (!$platform) {
                Log::warning('打包产品价格推送任务：平台不存在', [
                    'ota_platform_id' => $this->otaPlatformId,
                ]);
                return;
            }

            Log::info('打包产品价格推送任务：开始推送', [
                'pkg_product_id' => $this->pkgProductId,
                'hotel_id' => $this->hotelId,
                'room_type_id' => $this->roomTypeId,
                'ota_platform_code' => $platform->code->value,
            ]);

            // 根据平台类型选择推送方法
            $result = null;
            if ($platform->code->value === OtaPlatform::CTRIP->value) {
                $result = $priceService->syncToCtrip(
                    $pkgProduct,
                    $this->hotelId,
                    $this->roomTypeId,
                    $this->dates
                );
            } elseif ($platform->code->value === OtaPlatform::MEITUAN->value) {
                $startDate = $this->dates ? (min($this->dates) ?? null) : null;
                $endDate = $this->dates ? (max($this->dates) ?? null) : null;
                $result = $priceService->syncToMeituan(
                    $pkgProduct,
                    $this->hotelId,
                    $this->roomTypeId,
                    $startDate,
                    $endDate
                );
            } else {
                Log::warning('打包产品价格推送任务：不支持的平台', [
                    'ota_platform_id' => $this->otaPlatformId,
                    'platform_code' => $platform->code->value,
                ]);
                return;
            }

            if ($result && ($result['success'] ?? false)) {
                Log::info('打包产品价格推送任务：推送成功', [
                    'pkg_product_id' => $this->pkgProductId,
                    'hotel_id' => $this->hotelId,
                    'room_type_id' => $this->roomTypeId,
                    'ota_platform_code' => $platform->code->value,
                ]);
            } else {
                Log::error('打包产品价格推送任务：推送失败', [
                    'pkg_product_id' => $this->pkgProductId,
                    'hotel_id' => $this->hotelId,
                    'room_type_id' => $this->roomTypeId,
                    'ota_platform_code' => $platform->code->value,
                    'result' => $result,
                ]);
                // 推送失败时抛出异常，触发重试
                throw new \Exception($result['message'] ?? '推送失败');
            }

        } catch (\Exception $e) {
            Log::error('打包产品价格推送任务异常', [
                'pkg_product_id' => $this->pkgProductId,
                'hotel_id' => $this->hotelId,
                'room_type_id' => $this->roomTypeId,
                'ota_platform_id' => $this->otaPlatformId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
