<?php

namespace App\Jobs;

use App\Models\RoomType;
use App\Models\Product;
use App\Models\OtaPlatform;
use App\Enums\OtaPlatform as OtaPlatformEnum;
use App\Services\OTA\CtripService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 推送变化的库存到OTA平台
 * 
 * 统一队列任务，用于处理库存变化后的自动推送到OTA平台
 * 支持增量推送（只推送变化的日期）
 */
class PushChangedInventoryToOtaJob implements ShouldQueue
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
     * 
     * @param int $roomTypeId 房型ID
     * @param array $dates 需要推送的日期数组，格式：['2025-12-27', '2025-12-28']
     * @param int|null $otaPlatformId OTA平台ID，如果为null则推送到所有已推送的平台（默认携程）
     */
    public function __construct(
        public int $roomTypeId,
        public array $dates,
        public ?int $otaPlatformId = null
    ) {
        $this->onQueue('ota-push');
    }

    /**
     * Execute the job.
     */
    public function handle(CtripService $ctripService): void
    {
        Log::info('PushChangedInventoryToOtaJob 开始执行', [
            'room_type_id' => $this->roomTypeId,
            'dates' => $this->dates,
            'dates_count' => count($this->dates),
            'ota_platform_id' => $this->otaPlatformId,
        ]);

        try {
            // 加载房型
            $roomType = RoomType::with('hotel')->find($this->roomTypeId);
            if (!$roomType) {
                Log::warning('推送库存变化：房型不存在', [
                    'room_type_id' => $this->roomTypeId,
                ]);
                return;
            }

            $hotel = $roomType->hotel;
            if (!$hotel) {
                Log::warning('推送库存变化：酒店不存在', [
                    'room_type_id' => $this->roomTypeId,
                ]);
                return;
            }

            // 查找关联该房型的所有产品
            $products = Product::whereHas('prices', function ($q) {
                $q->where('room_type_id', $this->roomTypeId);
            })
            ->where('is_active', true)
            ->get();

            if ($products->isEmpty()) {
                Log::info('推送库存变化：没有关联的产品', [
                    'room_type_id' => $this->roomTypeId,
                ]);
                return;
            }

            // 确定要推送的OTA平台
            $otaPlatforms = [];
            if ($this->otaPlatformId) {
                $platform = OtaPlatform::find($this->otaPlatformId);
                if ($platform) {
                    $otaPlatforms[] = $platform;
                }
            } else {
                // 默认只推送到携程
                $ctripPlatform = OtaPlatform::where('code', OtaPlatformEnum::CTRIP->value)->first();
                if ($ctripPlatform) {
                    $otaPlatforms[] = $ctripPlatform;
                }
            }

            if (empty($otaPlatforms)) {
                Log::warning('推送库存变化：未找到OTA平台', [
                    'ota_platform_id' => $this->otaPlatformId,
                ]);
                return;
            }

            // 对每个产品和OTA平台组合进行推送
            foreach ($products as $product) {
                // 检查产品编码
                if (empty($product->code)) {
                    Log::warning('推送库存变化：产品编码为空', [
                        'product_id' => $product->id,
                        'room_type_id' => $this->roomTypeId,
                    ]);
                    continue;
                }

                // 检查酒店和房型编码
                if (empty($hotel->code) || empty($roomType->code)) {
                    Log::warning('推送库存变化：酒店或房型编码为空', [
                        'product_id' => $product->id,
                        'hotel_id' => $hotel->id,
                        'room_type_id' => $roomType->id,
                        'hotel_code' => $hotel->code,
                        'room_type_code' => $roomType->code,
                    ]);
                    continue;
                }

                foreach ($otaPlatforms as $platform) {
                    // 检查产品是否已推送到该OTA平台
                    $otaProduct = $product->otaProducts()
                        ->where('ota_platform_id', $platform->id)
                        ->where('is_active', true)
                        ->first();

                    if (!$otaProduct) {
                        Log::info('推送库存变化：产品未推送到该OTA平台', [
                            'product_id' => $product->id,
                            'ota_platform_id' => $platform->id,
                            'platform_code' => $platform->code->value,
                        ]);
                        continue;
                    }

                    // 根据平台类型推送
                    match ($platform->code->value) {
                        'ctrip' => $this->pushToCtrip($product, $hotel, $roomType, $ctripService),
                        default => Log::info('推送库存变化：暂不支持该OTA平台', [
                            'product_id' => $product->id,
                            'platform_code' => $platform->code->value,
                        ]),
                    };
                }
            }

        } catch (\Exception $e) {
            Log::error('推送库存变化异常', [
                'room_type_id' => $this->roomTypeId,
                'dates' => $this->dates,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 推送到携程
     */
    protected function pushToCtrip(
        Product $product,
        \App\Models\Hotel $hotel,
        RoomType $roomType,
        CtripService $ctripService
    ): void {
        try {
            // 推送到携程（只推送指定日期的库存）
            $result = $ctripService->syncProductStockByCombo(
                $product,
                $hotel,
                $roomType,
                $this->dates,
                'DATE_REQUIRED'
            );

            $resultCode = $result['header']['resultCode'] ?? null;
            $resultMessage = $result['header']['resultMessage'] ?? null;

            if ($resultCode === '0000') {
                Log::info('库存变化自动推送到携程成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'dates' => $this->dates,
                    'dates_count' => count($this->dates),
                ]);
            } else {
                Log::warning('库存变化自动推送到携程失败', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'dates' => $this->dates,
                    'result_code' => $resultCode,
                    'result_message' => $resultMessage,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('库存变化自动推送到携程异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'dates' => $this->dates,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}

