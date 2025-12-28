<?php

namespace App\Jobs;

use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Inventory;
use App\Models\ResourceSyncLog;
use App\Enums\PriceSource;
use App\Services\InventoryFilterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

/**
 * 异步处理景区方库存推送
 * 使用 Redis 快速过滤，只处理变化的库存
 */
class ProcessResourceInventoryPushJob implements ShouldQueue
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
     * 任务的唯一标识（用于防止同一景区并发处理）
     */
    public ?string $uniqueId = null;

    /**
     * @param string $rawBody XML 原始数据
     */
    public function __construct(
        public string $rawBody
    ) {
        // 从 XML 中提取景区标识，用于防止并发
        try {
            $xmlObj = new SimpleXMLElement($rawBody);
            $roomQuotaMapJson = (string)$xmlObj->RoomQuotaMap;
            $roomQuotaMap = json_decode($roomQuotaMapJson, true);
            
            // 使用第一个酒店的标识作为唯一ID
            if (!empty($roomQuotaMap)) {
                $firstHotel = $roomQuotaMap[0];
                $this->uniqueId = 'resource_push:' . ($firstHotel['hotelNo'] ?? 'unknown');
            }
        } catch (\Exception $e) {
            $this->uniqueId = 'resource_push:unknown';
        }
    }

    /**
     * 获取任务的唯一标识（用于防止并发）
     */
    public function uniqueId(): ?string
    {
        return $this->uniqueId;
    }

    public function handle(InventoryFilterService $inventoryFilterService): void
    {
        try {
            Log::info('ProcessResourceInventoryPushJob 开始执行', [
                'unique_id' => $this->uniqueId,
                'body_length' => strlen($this->rawBody),
            ]);

            // 解析 XML
            $xmlObj = new SimpleXMLElement($this->rawBody);
            $roomQuotaMapJson = (string)$xmlObj->RoomQuotaMap;

            if (empty($roomQuotaMapJson)) {
                Log::warning('ProcessResourceInventoryPushJob: RoomQuotaMap为空');
                return;
            }

            $roomQuotaMap = json_decode($roomQuotaMapJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('ProcessResourceInventoryPushJob: JSON解析失败', [
                    'error' => json_last_error_msg(),
                ]);
                return;
            }

            // 处理逻辑
            $allChangedItems = [];
            $successCount = 0;
            $failCount = 0;
            $errors = [];

            foreach ($roomQuotaMap as $hotelData) {
                $hotelNo = $hotelData['hotelNo'] ?? null;
                $roomType = $hotelData['roomType'] ?? null;
                $roomQuota = $hotelData['roomQuota'] ?? [];

                if (!$hotelNo || !$roomType) {
                    $failCount++;
                    $errors[] = "缺少hotelNo或roomType";
                    continue;
                }

                try {
                    // 查找酒店和房型
                    $hotel = Hotel::where('external_code', $hotelNo)
                        ->orWhere('code', $hotelNo)
                        ->first();

                    if (!$hotel) {
                        $failCount++;
                        $errors[] = "未找到酒店：{$hotelNo}";
                        Log::warning('ProcessResourceInventoryPushJob: 未找到酒店', [
                            'hotel_no' => $hotelNo,
                        ]);
                        continue;
                    }

                    $roomTypeModel = RoomType::where('hotel_id', $hotel->id)
                        ->where(function($query) use ($roomType) {
                            $query->where('external_code', $roomType)
                                  ->orWhere('name', $roomType);
                        })
                        ->first();

                    if (!$roomTypeModel) {
                        $failCount++;
                        $errors[] = "未找到房型：{$roomType}（酒店：{$hotelNo}）";
                        Log::warning('ProcessResourceInventoryPushJob: 未找到房型', [
                            'hotel_id' => $hotel->id,
                            'room_type' => $roomType,
                        ]);
                        continue;
                    }

                    // 准备批量比对的数据
                    $itemsToCheck = [];
                    foreach ($roomQuota as $quotaData) {
                        $date = $quotaData['date'] ?? null;
                        $quota = (int)($quotaData['quota'] ?? 0);

                        if (!$date) {
                            continue;
                        }

                        $itemsToCheck[] = [
                            'hotel_id' => $hotel->id,
                            'room_type_id' => $roomTypeModel->id,
                            'date' => $date,
                            'quantity' => $quota,
                        ];
                    }

                    if (empty($itemsToCheck)) {
                        continue;
                    }

                    // 使用 Redis 快速过滤
                    $changed = $inventoryFilterService->filterChanged($itemsToCheck);

                    if (empty($changed)) {
                        // 没有变化，跳过数据库更新
                        Log::info('ProcessResourceInventoryPushJob: 无变化，跳过', [
                            'hotel_id' => $hotel->id,
                            'room_type_id' => $roomTypeModel->id,
                            'item_count' => count($itemsToCheck),
                        ]);
                        continue;
                    }

                    // 只更新变化的库存到数据库
                    DB::beginTransaction();
                    try {
                        foreach ($changed as $item) {
                            $inventory = Inventory::firstOrNew([
                                'room_type_id' => $item['room_type_id'],
                                'date' => $item['date'],
                            ]);
                            
                            $inventory->total_quantity = $item['quantity'];
                            $inventory->available_quantity = $item['quantity'];
                            $inventory->source = PriceSource::API;
                            $inventory->save();
                        }
                        
                        DB::commit();
                        $successCount++;
                        
                        // 收集变化的项目用于推送
                        $allChangedItems = array_merge($allChangedItems, $changed);
                        
                        // 记录同步日志
                        $scenicSpot = $hotel->scenicSpot ?? null;
                        if ($scenicSpot) {
                            ResourceSyncLog::create([
                                'software_provider_id' => $scenicSpot->software_provider_id ?? null,
                                'scenic_spot_id' => $scenicSpot->id,
                                'sync_type' => 'inventory',
                                'sync_mode' => 'push',
                                'status' => 'success',
                                'message' => "成功更新库存：酒店{$hotelNo}，房型{$roomType}，变化" . count($changed) . "条",
                                'synced_count' => count($changed),
                                'last_synced_at' => now(),
                            ]);
                        }

                    } catch (\Exception $e) {
                        DB::rollBack();
                        $failCount++;
                        $errors[] = "更新库存失败：{$e->getMessage()}";
                        Log::error('ProcessResourceInventoryPushJob: 更新库存失败', [
                            'hotel_id' => $hotel->id,
                            'room_type_id' => $roomTypeModel->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                } catch (\Exception $e) {
                    $failCount++;
                    $errors[] = "处理失败：{$e->getMessage()}";
                    Log::error('ProcessResourceInventoryPushJob: 处理失败', [
                        'hotel_no' => $hotelNo,
                        'room_type' => $roomType,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 批量触发 OTA 推送
            if (!empty($allChangedItems)) {
                // 批量处理，每批最多100条，避免单个任务过大
                $batches = array_chunk($allChangedItems, 100);
                
                foreach ($batches as $batch) {
                    PushChangedInventoryToOtaJob::dispatch($batch)
                        ->onQueue('ota-push');
                }

                Log::info('ProcessResourceInventoryPushJob: 已触发OTA推送任务', [
                    'changed_count' => count($allChangedItems),
                    'batches' => count($batches),
                ]);
            }

            if ($failCount > 0) {
                Log::warning('ProcessResourceInventoryPushJob: 部分失败', [
                    'success_count' => $successCount,
                    'fail_count' => $failCount,
                    'errors' => $errors,
                ]);
            }

            Log::info('ProcessResourceInventoryPushJob 执行完成', [
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'changed_count' => count($allChangedItems),
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessResourceInventoryPushJob 处理异常', [
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
        Log::error('ProcessResourceInventoryPushJob 执行失败', [
            'unique_id' => $this->uniqueId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

