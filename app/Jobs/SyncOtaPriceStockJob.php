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

            // 检查平台类型
            $platform = \App\Models\OtaPlatform::find($this->otaPlatformId);
            if (!$platform) {
                Log::info('同步任务：平台不存在', ['ota_platform_id' => $this->otaPlatformId]);
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

            // 根据平台类型选择服务
            if ($platform->code->value === OtaPlatform::CTRIP->value) {
                // 携程平台：使用CtripService
                if ($this->syncPrice) {
                    $this->syncPriceForCtrip($ctripService, $product, $hotel, $roomType, $syncLog);
                }
                if ($this->syncStock) {
                    $this->syncStockForCtrip($ctripService, $product, $hotel, $roomType, $syncLog);
                }
            } elseif ($platform->code->value === OtaPlatform::MEITUAN->value) {
                // 美团平台：使用MeituanService
                $meituanService = app(\App\Services\OTA\MeituanService::class);
                if ($this->syncPrice || $this->syncStock) {
                    $this->syncForMeituan($meituanService, $product, $hotel, $roomType, $syncLog);
                }
            } else {
                Log::info('同步任务：不支持的平台', ['ota_platform_id' => $this->otaPlatformId]);
                return;
            }
        } catch (\Exception $e) {
            Log::error('同步任务异常', [
                'product_id' => $this->productId,
                'hotel_id' => $this->hotelId,
                'room_type_id' => $this->roomTypeId,
                'ota_platform_id' => $this->otaPlatformId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 同步携程价格
     */
    protected function syncPriceForCtrip(
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
     * 同步携程库存
     */
    protected function syncStockForCtrip(
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

    /**
     * 同步美团价格库存（多层价格日历变化通知V2）
     */
    protected function syncForMeituan(
        \App\Services\OTA\MeituanService $meituanService,
        Product $product,
        Hotel $hotel,
        RoomType $roomType,
        OtaProductSyncLog $syncLog
    ): void {
        try {
            // 获取价格和库存数据
            $prices = $product->prices()
                ->where('room_type_id', $roomType->id)
                ->get();

            $inventories = \App\Models\Inventory::where('room_type_id', $roomType->id)->get();

            if ($prices->isEmpty() && $inventories->isEmpty()) {
                Log::info('同步美团：没有价格和库存数据', [
                    'product_id' => $product->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 构建价格和库存数据用于哈希计算
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

            $stockData = $inventories->map(function ($inventory) {
                return [
                    'date' => $inventory->date->format('Y-m-d'),
                    'available_quantity' => $inventory->available_quantity,
                    'is_closed' => $inventory->is_closed,
                ];
            })->toArray();

            // 计算哈希值（合并价格和库存）
            $combinedData = [
                'prices' => $priceData,
                'stocks' => $stockData,
            ];
            $currentHash = md5(json_encode($combinedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 检查是否有变化（美团使用多层价格日历，价格和库存一起推送）
            $lastHash = $syncLog->last_price_hash . '_' . $syncLog->last_stock_hash;
            if ($lastHash === $currentHash) {
                Log::info('同步美团：价格库存未变化，跳过', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 获取销售日期范围
            $startDate = $product->sale_start_date 
                ? $product->sale_start_date->format('Y-m-d') 
                : now()->format('Y-m-d');
            $endDate = $product->sale_end_date 
                ? $product->sale_end_date->format('Y-m-d') 
                : now()->addMonths(3)->format('Y-m-d');

            // 同步多层价格日历
            $result = $meituanService->syncLevelPriceStock($product, $hotel, $roomType, $startDate, $endDate);

            if ($result['success'] ?? false) {
                // 更新同步日志
                $syncLog->update([
                    'last_price_hash' => md5(json_encode($priceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'last_stock_hash' => md5(json_encode($stockData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'last_price_synced_at' => now(),
                    'last_stock_synced_at' => now(),
                    'last_price_data' => $priceData,
                    'last_stock_data' => $stockData,
                ]);

                Log::info('同步美团：成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
            } else {
                Log::error('同步美团：失败', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('同步美团异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 同步美团价格库存（多层价格日历变化通知V2）
     */
    protected function syncForMeituan(
        \App\Services\OTA\MeituanService $meituanService,
        Product $product,
        Hotel $hotel,
        RoomType $roomType,
        OtaProductSyncLog $syncLog
    ): void {
        try {
            // 获取价格和库存数据
            $prices = $product->prices()
                ->where('room_type_id', $roomType->id)
                ->get();

            $inventories = \App\Models\Inventory::where('room_type_id', $roomType->id)->get();

            if ($prices->isEmpty() && $inventories->isEmpty()) {
                Log::info('同步美团：没有价格和库存数据', [
                    'product_id' => $product->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 构建价格和库存数据用于哈希计算
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

            $stockData = $inventories->map(function ($inventory) {
                return [
                    'date' => $inventory->date->format('Y-m-d'),
                    'available_quantity' => $inventory->available_quantity,
                    'is_closed' => $inventory->is_closed,
                ];
            })->toArray();

            // 计算哈希值（合并价格和库存）
            $combinedData = [
                'prices' => $priceData,
                'stocks' => $stockData,
            ];
            $currentHash = md5(json_encode($combinedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 检查是否有变化（美团使用多层价格日历，价格和库存一起推送）
            $lastHash = $syncLog->last_price_hash . '_' . $syncLog->last_stock_hash;
            if ($lastHash === $currentHash) {
                Log::info('同步美团：价格库存未变化，跳过', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 获取销售日期范围
            $startDate = $product->sale_start_date 
                ? $product->sale_start_date->format('Y-m-d') 
                : now()->format('Y-m-d');
            $endDate = $product->sale_end_date 
                ? $product->sale_end_date->format('Y-m-d') 
                : now()->addMonths(3)->format('Y-m-d');

            // 同步多层价格日历
            $result = $meituanService->syncLevelPriceStock($product, $hotel, $roomType, $startDate, $endDate);

            if ($result['success'] ?? false) {
                // 更新同步日志
                $syncLog->update([
                    'last_price_hash' => md5(json_encode($priceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'last_stock_hash' => md5(json_encode($stockData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'last_price_synced_at' => now(),
                    'last_stock_synced_at' => now(),
                    'last_price_data' => $priceData,
                    'last_stock_data' => $stockData,
                ]);

                Log::info('同步美团：成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
            } else {
                Log::error('同步美团：失败', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('同步美团异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 同步美团价格库存（多层价格日历变化通知V2）
     */
    protected function syncForMeituan(
        \App\Services\OTA\MeituanService $meituanService,
        Product $product,
        Hotel $hotel,
        RoomType $roomType,
        OtaProductSyncLog $syncLog
    ): void {
        try {
            // 获取价格和库存数据
            $prices = $product->prices()
                ->where('room_type_id', $roomType->id)
                ->get();

            $inventories = \App\Models\Inventory::where('room_type_id', $roomType->id)->get();

            if ($prices->isEmpty() && $inventories->isEmpty()) {
                Log::info('同步美团：没有价格和库存数据', [
                    'product_id' => $product->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 构建价格和库存数据用于哈希计算
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

            $stockData = $inventories->map(function ($inventory) {
                return [
                    'date' => $inventory->date->format('Y-m-d'),
                    'available_quantity' => $inventory->available_quantity,
                    'is_closed' => $inventory->is_closed,
                ];
            })->toArray();

            // 计算哈希值（合并价格和库存）
            $combinedData = [
                'prices' => $priceData,
                'stocks' => $stockData,
            ];
            $currentHash = md5(json_encode($combinedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 检查是否有变化（美团使用多层价格日历，价格和库存一起推送）
            $lastHash = $syncLog->last_price_hash . '_' . $syncLog->last_stock_hash;
            if ($lastHash === $currentHash) {
                Log::info('同步美团：价格库存未变化，跳过', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 获取销售日期范围
            $startDate = $product->sale_start_date 
                ? $product->sale_start_date->format('Y-m-d') 
                : now()->format('Y-m-d');
            $endDate = $product->sale_end_date 
                ? $product->sale_end_date->format('Y-m-d') 
                : now()->addMonths(3)->format('Y-m-d');

            // 同步多层价格日历
            $result = $meituanService->syncLevelPriceStock($product, $hotel, $roomType, $startDate, $endDate);

            if ($result['success'] ?? false) {
                // 更新同步日志
                $syncLog->update([
                    'last_price_hash' => md5(json_encode($priceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'last_stock_hash' => md5(json_encode($stockData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'last_price_synced_at' => now(),
                    'last_stock_synced_at' => now(),
                    'last_price_data' => $priceData,
                    'last_stock_data' => $stockData,
                ]);

                Log::info('同步美团：成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
            } else {
                Log::error('同步美团：失败', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('同步美团异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 同步美团价格库存（多层价格日历变化通知V2）
     */
    protected function syncForMeituan(
        \App\Services\OTA\MeituanService $meituanService,
        Product $product,
        Hotel $hotel,
        RoomType $roomType,
        OtaProductSyncLog $syncLog
    ): void {
        try {
            // 获取价格和库存数据
            $prices = $product->prices()
                ->where('room_type_id', $roomType->id)
                ->get();

            $inventories = \App\Models\Inventory::where('room_type_id', $roomType->id)->get();

            if ($prices->isEmpty() && $inventories->isEmpty()) {
                Log::info('同步美团：没有价格和库存数据', [
                    'product_id' => $product->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 构建价格和库存数据用于哈希计算
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

            $stockData = $inventories->map(function ($inventory) {
                return [
                    'date' => $inventory->date->format('Y-m-d'),
                    'available_quantity' => $inventory->available_quantity,
                    'is_closed' => $inventory->is_closed,
                ];
            })->toArray();

            // 计算哈希值（合并价格和库存）
            $combinedData = [
                'prices' => $priceData,
                'stocks' => $stockData,
            ];
            $currentHash = md5(json_encode($combinedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 检查是否有变化（美团使用多层价格日历，价格和库存一起推送）
            $lastHash = $syncLog->last_price_hash . '_' . $syncLog->last_stock_hash;
            if ($lastHash === $currentHash) {
                Log::info('同步美团：价格库存未变化，跳过', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 获取销售日期范围
            $startDate = $product->sale_start_date 
                ? $product->sale_start_date->format('Y-m-d') 
                : now()->format('Y-m-d');
            $endDate = $product->sale_end_date 
                ? $product->sale_end_date->format('Y-m-d') 
                : now()->addMonths(3)->format('Y-m-d');

            // 同步多层价格日历
            $result = $meituanService->syncLevelPriceStock($product, $hotel, $roomType, $startDate, $endDate);

            if ($result['success'] ?? false) {
                // 更新同步日志
                $syncLog->update([
                    'last_price_hash' => md5(json_encode($priceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'last_stock_hash' => md5(json_encode($stockData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'last_price_synced_at' => now(),
                    'last_stock_synced_at' => now(),
                    'last_price_data' => $priceData,
                    'last_stock_data' => $stockData,
                ]);

                Log::info('同步美团：成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);
            } else {
                Log::error('同步美团：失败', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'result' => $result,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('同步美团异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}