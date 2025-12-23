<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OtaPlatform;
use App\Models\Hotel;
use App\Models\OtaProduct;
use App\Models\OtaProductSyncLog;
use App\Models\Product;
use App\Models\RoomType;
use App\Services\OTA\CtripService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncOtaPriceStockJob implements ShouldQueue
{
    use Queueable;

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
        public int $productId,
        public int $hotelId,
        public int $roomTypeId,
        public int $otaPlatformId,
        public bool $syncPrice = true,
        public bool $syncStock = true
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CtripService $ctripService): void
    {
        try {
            // 加载数据
            $product = Product::find($this->productId);
            $hotel = Hotel::find($this->hotelId);
            $roomType = RoomType::find($this->roomTypeId);

            if (!$product || !$hotel || !$roomType) {
                Log::warning('同步任务：数据不存在', [
                    'product_id' => $this->productId,
                    'hotel_id' => $this->hotelId,
                    'room_type_id' => $this->roomTypeId,
                ]);
                return;
            }

            // 检查产品是否启用
            if (!$product->is_active) {
                Log::info('同步任务：产品未启用，跳过', ['product_id' => $this->productId]);
                return;
            }

            // 检查是否已推送到该OTA平台
            $otaProduct = OtaProduct::where('product_id', $this->productId)
                ->where('ota_platform_id', $this->otaPlatformId)
                ->where('is_active', true)
                ->first();

            if (!$otaProduct) {
                Log::info('同步任务：产品未推送到该OTA平台', [
                    'product_id' => $this->productId,
                    'ota_platform_id' => $this->otaPlatformId,
                ]);
                return;
            }

            // 只处理携程平台
            $platform = \App\Models\OtaPlatform::find($this->otaPlatformId);
            if (!$platform || $platform->code->value !== OtaPlatform::CTRIP->value) {
                Log::info('同步任务：非携程平台，跳过', ['ota_platform_id' => $this->otaPlatformId]);
                return;
            }

            // 获取或创建同步日志
            $syncLog = OtaProductSyncLog::firstOrCreate(
                [
                    'product_id' => $this->productId,
                    'hotel_id' => $this->hotelId,
                    'room_type_id' => $this->roomTypeId,
                    'ota_platform_id' => $this->otaPlatformId,
                ]
            );

            // 同步价格
            if ($this->syncPrice) {
                $this->syncPrice($ctripService, $product, $hotel, $roomType, $syncLog);
            }

            // 同步库存
            if ($this->syncStock) {
                $this->syncStock($ctripService, $product, $hotel, $roomType, $syncLog);
            }
        } catch (\Exception $e) {
            Log::error('同步任务执行失败', [
                'product_id' => $this->productId,
                'hotel_id' => $this->hotelId,
                'room_type_id' => $this->roomTypeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 同步价格
     */
    protected function syncPrice(
        CtripService $ctripService,
        Product $product,
        Hotel $hotel,
        RoomType $roomType,
        OtaProductSyncLog $syncLog
    ): void {
        try {
            // 获取当前价格数据
            $prices = $product->prices()
                ->where('room_type_id', $roomType->id)
                ->get();

            if ($prices->isEmpty()) {
                Log::info('同步价格：没有价格数据', [
                    'product_id' => $product->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 构建价格数据用于哈希计算
            $priceData = $prices->map(function ($price) use ($product, $roomType) {
                $calculatedPrice = app(\App\Services\ProductService::class)->calculatePrice(
                    $product,
                    $roomType->id,
                    $price->date->format('Y-m-d')
                );
                return [
                    'date' => $price->date->format('Y-m-d'),
                    'sale_price' => $calculatedPrice['sale_price'],
                    'settlement_price' => $calculatedPrice['settlement_price'],
                ];
            })->toArray();

            // 计算哈希值
            $currentHash = md5(json_encode($priceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 检查是否有变化
            if ($syncLog->last_price_hash === $currentHash) {
                Log::info('同步价格：价格未变化，跳过', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 同步价格
            $result = $ctripService->syncProductPriceByCombo($product, $hotel, $roomType, null, 'DATE_REQUIRED');

            if ($result['success'] ?? false) {
                // 更新同步日志
                $syncLog->update([
                    'last_price_hash' => $currentHash,
                    'last_price_synced_at' => now(),
                    'last_price_data' => $priceData,
                ]);

                Log::info('同步价格：成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
            } else {
                Log::error('同步价格：失败', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('同步价格异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 同步库存
     */
    protected function syncStock(
        CtripService $ctripService,
        Product $product,
        Hotel $hotel,
        RoomType $roomType,
        OtaProductSyncLog $syncLog
    ): void {
        try {
            // 获取当前库存数据
            $inventories = \App\Models\Inventory::where('room_type_id', $roomType->id)->get();

            if ($inventories->isEmpty()) {
                Log::info('同步库存：没有库存数据', [
                    'product_id' => $product->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 构建库存数据用于哈希计算
            $stockData = $inventories->map(function ($inventory) {
                return [
                    'date' => $inventory->date->format('Y-m-d'),
                    'available_quantity' => $inventory->available_quantity,
                    'is_closed' => $inventory->is_closed,
                ];
            })->toArray();

            // 计算哈希值
            $currentHash = md5(json_encode($stockData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 检查是否有变化
            if ($syncLog->last_stock_hash === $currentHash) {
                Log::info('同步库存：库存未变化，跳过', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 同步库存
            $result = $ctripService->syncProductStockByCombo($product, $hotel, $roomType, null, 'DATE_REQUIRED');

            if ($result['success'] ?? false) {
                // 更新同步日志
                $syncLog->update([
                    'last_stock_hash' => $currentHash,
                    'last_stock_synced_at' => now(),
                    'last_stock_data' => $stockData,
                ]);

                Log::info('同步库存：成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
            } else {
                Log::error('同步库存：失败', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('同步库存异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
