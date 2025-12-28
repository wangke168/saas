<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\RoomType;
use App\Services\OTA\CtripService;
use App\Services\OTA\MeituanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PushChangedInventoryToOtaJob implements ShouldQueue
{
    use Queueable, FoundationQueueable;

    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 300;

    /**
     * @param array $changedItems 变化的库存列表，格式：
     * [
     *   [
     *     'room_type_id' => 1,
     *     'date' => '2025-12-27',
     *     'hotel_id' => 1,
     *     'quantity' => 100,
     *     'price' => 1200.00,  // 可选
     *   ],
     *   ...
     * ]
     */
    public function __construct(
        public array $changedItems
    ) {}

    public function handle(
        CtripService $ctripService,
        MeituanService $meituanService
    ): void {
        if (empty($this->changedItems)) {
            Log::info('PushChangedInventoryToOtaJob: 没有变化的库存，跳过');
            return;
        }

        Log::info('PushChangedInventoryToOtaJob 开始执行', [
            'changed_count' => count($this->changedItems),
        ]);

        // 按 room_type_id 分组
        $groupedByRoomType = collect($this->changedItems)
            ->groupBy('room_type_id');

        foreach ($groupedByRoomType as $roomTypeId => $items) {
            $roomType = RoomType::with(['hotel.scenicSpot'])->find($roomTypeId);
            if (!$roomType || !$roomType->hotel) {
                Log::warning('PushChangedInventoryToOtaJob: 未找到房型或酒店', [
                    'room_type_id' => $roomTypeId,
                ]);
                continue;
            }

            // 提取变化的日期
            $changedDates = $items->pluck('date')->unique()->toArray();
            
            // 判断是否有价格变化
            $hasPriceChange = $items->contains(function ($item) {
                return isset($item['price']) || isset($item['sale_price']);
            });

            // 查找使用该房型的所有产品
            $products = Product::whereHas('prices', function ($query) use ($roomTypeId) {
                $query->where('room_type_id', $roomTypeId);
            })
            ->where('is_active', true)
            ->get();

            if ($products->isEmpty()) {
                Log::info('PushChangedInventoryToOtaJob: 未找到使用该房型的产品', [
                    'room_type_id' => $roomTypeId,
                ]);
                continue;
            }

            foreach ($products as $product) {
                $otaProducts = $product->otaProducts()
                    ->where('is_active', true)
                    ->with('otaPlatform')
                    ->get();

                if ($otaProducts->isEmpty()) {
                    continue;
                }

                foreach ($otaProducts as $otaProduct) {
                    $platform = $otaProduct->otaPlatform;
                    if (!$platform) {
                        continue;
                    }

                    try {
                        if ($platform->code->value === 'ctrip') {
                            // 携程：分开推送
                            if ($hasPriceChange) {
                                $this->pushPriceToCtrip(
                                    $ctripService,
                                    $product,
                                    $roomType->hotel,
                                    $roomType,
                                    $changedDates
                                );
                            }
                            
                            $this->pushStockToCtrip(
                                $ctripService,
                                $product,
                                $roomType->hotel,
                                $roomType,
                                $changedDates
                            );
                            
                        } elseif ($platform->code->value === 'meituan') {
                            // 美团：合并推送
                            $startDate = min($changedDates);
                            $endDate = max($changedDates);
                            
                            $this->pushPriceStockToMeituan(
                                $meituanService,
                                $product,
                                $roomType->hotel,
                                $roomType,
                                $startDate,
                                $endDate
                            );
                        }
                    } catch (\Exception $e) {
                        Log::error('推送价格库存到OTA失败', [
                            'product_id' => $product->id,
                            'room_type_id' => $roomTypeId,
                            'platform' => $platform->code->value ?? 'unknown',
                            'dates' => $changedDates,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        throw $e; // 触发重试
                    }
                }
            }
        }

        Log::info('PushChangedInventoryToOtaJob 执行完成', [
            'changed_count' => count($this->changedItems),
        ]);
    }

    /**
     * 推送价格到携程
     */
    protected function pushPriceToCtrip(
        CtripService $service,
        Product $product,
        $hotel,
        RoomType $roomType,
        array $dates
    ): void {
        // 检查是否有 syncProductPriceByCombo 方法
        if (!method_exists($service, 'syncProductPriceByCombo')) {
            Log::warning('PushChangedInventoryToOtaJob: CtripService 不支持 syncProductPriceByCombo', [
                'product_id' => $product->id,
            ]);
            return;
        }

        $result = $service->syncProductPriceByCombo(
            $product,
            $hotel,
            $roomType,
            $dates,
            'DATE_REQUIRED'
        );

        Log::info('推送变化的价格到携程', [
            'product_id' => $product->id,
            'room_type_id' => $roomType->id,
            'dates' => $dates,
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? '',
        ]);
    }

    /**
     * 推送库存到携程
     */
    protected function pushStockToCtrip(
        CtripService $service,
        Product $product,
        $hotel,
        RoomType $roomType,
        array $dates
    ): void {
        // 检查是否有 syncProductStockByCombo 方法
        if (!method_exists($service, 'syncProductStockByCombo')) {
            Log::warning('PushChangedInventoryToOtaJob: CtripService 不支持 syncProductStockByCombo', [
                'product_id' => $product->id,
            ]);
            return;
        }

        $result = $service->syncProductStockByCombo(
            $product,
            $hotel,
            $roomType,
            $dates,
            'DATE_REQUIRED'
        );

        Log::info('推送变化的库存到携程', [
            'product_id' => $product->id,
            'room_type_id' => $roomType->id,
            'dates' => $dates,
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? '',
        ]);
    }

    /**
     * 推送价格+库存到美团
     */
    protected function pushPriceStockToMeituan(
        MeituanService $service,
        Product $product,
        $hotel,
        RoomType $roomType,
        string $startDate,
        string $endDate
    ): void {
        // 检查是否有 syncLevelPriceStock 方法
        if (!method_exists($service, 'syncLevelPriceStock')) {
            Log::warning('PushChangedInventoryToOtaJob: MeituanService 不支持 syncLevelPriceStock', [
                'product_id' => $product->id,
            ]);
            return;
        }

        $result = $service->syncLevelPriceStock(
            $product,
            $hotel,
            $roomType,
            $startDate,
            $endDate
        );

        Log::info('推送变化的价格库存到美团', [
            'product_id' => $product->id,
            'room_type_id' => $roomType->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? '',
        ]);
    }

    /**
     * 任务失败时的处理
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PushChangedInventoryToOtaJob 执行失败', [
            'changed_count' => count($this->changedItems),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

