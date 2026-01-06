<?php

namespace App\Jobs\Pkg;

use App\Enums\OtaPlatform;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\Pkg\PkgProduct;
use App\Services\Pkg\PkgProductOtaSyncService;
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
    public function handle(PkgProductOtaSyncService $syncService): void
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

            // 加载酒店和房型
            $hotel = \App\Models\Res\ResHotel::find($this->hotelId);
            $roomType = \App\Models\Res\ResRoomType::find($this->roomTypeId);
            
            if (!$hotel || !$roomType) {
                Log::warning('打包产品价格推送任务：酒店或房型不存在', [
                    'hotel_id' => $this->hotelId,
                    'room_type_id' => $this->roomTypeId,
                ]);
                return;
            }
            
            // 使用新的同步服务推送单个房型的价格
            // 注意：syncProductPricesToOta 会推送所有房型，但我们可以通过检查结果来确认目标房型是否成功
            $result = $syncService->syncProductPricesToOta(
                $pkgProduct,
                $platform->code->value,
                $this->dates
            );
            
            // 从结果中提取目标房型的推送结果
            $targetResult = null;
            if (isset($result['details']) && is_array($result['details'])) {
                $targetResult = collect($result['details'])->first(function ($detail) use ($hotel, $roomType) {
                    return ($detail['hotel'] ?? '') === $hotel->name
                        && ($detail['room_type'] ?? '') === $roomType->name;
                });
            }
            
            // 如果找到了目标房型的结果，使用它；否则使用整体结果
            if ($targetResult) {
                $result = $targetResult;
            } else {
                // 如果没有找到目标房型，说明该房型可能不在产品的关联列表中
                Log::warning('打包产品价格推送任务：指定的房型不在产品关联列表中', [
                    'pkg_product_id' => $this->pkgProductId,
                    'hotel_id' => $this->hotelId,
                    'room_type_id' => $this->roomTypeId,
                ]);
                $result = [
                    'success' => false,
                    'message' => '指定的房型不在产品关联列表中',
                ];
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
