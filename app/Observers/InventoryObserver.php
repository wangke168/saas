<?php

namespace App\Observers;

use App\Models\Inventory;
use App\Enums\PriceSource;
use App\Enums\OtaPlatform;
use App\Jobs\PushChangedInventoryToOtaJob;
use App\Models\Product;
use App\Models\OtaPlatform as OtaPlatformModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 库存模型观察者
 * 
 * 监听库存变化事件，自动触发推送到OTA平台
 * 
 * 注意：
 * 1. 只处理非API推送的库存（source !== API），因为API推送的库存已经在资源方推送时处理
 * 2. 使用延迟和去重机制，合并短时间内多个库存变化
 */
class InventoryObserver
{
    /**
     * 库存保存后（创建或更新）
     * 
     * @param Inventory $inventory
     */
    public function saved(Inventory $inventory): void
    {
        // 检查是否需要自动推送
        if (!$this->shouldAutoPush($inventory)) {
            return;
        }

        // 检查环境变量是否启用自动推送
        if (!env('ENABLE_AUTO_PUSH_INVENTORY_TO_OTA', true)) {
            return;
        }

        // 使用延迟和去重机制，合并短时间内多个库存变化
        $this->scheduleOtaPush($inventory);
    }

    /**
     * 判断是否需要自动推送
     * 
     * @param Inventory $inventory
     * @return bool
     */
    protected function shouldAutoPush(Inventory $inventory): bool
    {
        // 只推送资源方推送的库存（source = API）会被跳过
        // 因为资源方推送的库存已经在 ResourceController 中通过 Redis 指纹比对处理
        return $inventory->source !== PriceSource::API;
    }

    /**
     * 调度OTA推送任务（使用延迟和去重机制）
     * 
     * @param Inventory $inventory
     */
    protected function scheduleOtaPush(Inventory $inventory): void
    {
        try {
            $roomType = $inventory->roomType;
            if (!$roomType) {
                Log::warning('InventoryObserver：房型不存在', [
                    'inventory_id' => $inventory->id,
                    'room_type_id' => $inventory->room_type_id,
                ]);
                return;
            }

            // 使用 Redis 缓存，合并5秒内的推送请求
            $cacheKey = "inventory_push:{$inventory->room_type_id}";
            $pushDelay = (int) env('INVENTORY_PUSH_DELAY_SECONDS', 5);
            
            $cachedDates = Cache::get($cacheKey, []);
            $dateStr = $inventory->date->format('Y-m-d');

            if (!in_array($dateStr, $cachedDates)) {
                $cachedDates[] = $dateStr;
                Cache::put($cacheKey, $cachedDates, now()->addSeconds($pushDelay + 1));
            }

            // 只在一个日期首次变化时创建任务（避免重复创建）
            $taskKey = "inventory_push_task:{$inventory->room_type_id}";
            if (!Cache::has($taskKey)) {
                // 延迟执行，合并多个库存变化
                $job = PushChangedInventoryToOtaJob::dispatch(
                    $inventory->room_type_id,
                    array_unique($cachedDates), // 包含所有需要推送的日期
                    null // 默认推送到携程
                )->onQueue('ota-push');
                
                // 只有在延迟时间 > 0 时才设置延迟
                if ($pushDelay > 0) {
                    $job->delay(now()->addSeconds($pushDelay));
                }

                // 标记任务已创建（防止短时间内重复创建）
                $taskTtl = $pushDelay > 0 ? $pushDelay : 1;
                Cache::put($taskKey, true, now()->addSeconds($taskTtl));

                Log::info('InventoryObserver：已调度OTA推送任务', [
                    'inventory_id' => $inventory->id,
                    'room_type_id' => $inventory->room_type_id,
                    'date' => $dateStr,
                    'dates' => array_unique($cachedDates),
                    'delay_seconds' => $pushDelay,
                    'job_id' => $job->getJobId() ?? 'unknown',
                ]);
            } else {
                Log::debug('InventoryObserver：推送任务已存在，跳过创建', [
                    'inventory_id' => $inventory->id,
                    'room_type_id' => $inventory->room_type_id,
                    'date' => $dateStr,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('InventoryObserver：调度OTA推送任务失败', [
                'inventory_id' => $inventory->id,
                'room_type_id' => $inventory->room_type_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // 不抛出异常，避免影响库存更新流程
        }
    }
}

