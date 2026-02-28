<?php

namespace App\Observers;

use App\Models\Inventory;
use App\Enums\PriceSource;
use App\Jobs\PushChangedInventoryToOtaJob;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Services\OTA\OtaInventoryHelper;
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
     * 库存更新前：缓存旧的可售数量，供 saved 时判断是否「变紧」或「恢复」
     */
    public function updating(Inventory $inventory): void
    {
        if (!$inventory->exists) {
            return;
        }
        $oldQty = $inventory->getOriginal('available_quantity');
        Cache::put("inventory_old_avail:{$inventory->id}", $oldQty, now()->addSeconds(30));
    }

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

        // 检查环境变量是否启用自动推送（全局开关）
        if (!env('ENABLE_AUTO_PUSH_INVENTORY_TO_OTA', true)) {
            return;
        }

        // 检查环境变量是否启用人工操作库存的自动推送（默认禁用）
        // 资源方推送的库存仍然自动推送（在 ResourceController 中处理）
        if (!env('ENABLE_AUTO_PUSH_MANUAL_INVENTORY_TO_OTA', false)) {
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

            $pushDelay = (int) env('INVENTORY_PUSH_DELAY_SECONDS', 5);
            $threshold = OtaInventoryHelper::getZeroThreshold();

            // 判断本次变更是否「变紧」或「恢复」，用于是否推美团
            $oldQty = Cache::pull("inventory_old_avail:{$inventory->id}");
            $newQty = (int) $inventory->available_quantity;
            $becameLow = $newQty <= $threshold && ($oldQty === null || (int) $oldQty > $threshold);
            $recovered = $newQty > $threshold && $oldQty !== null && (int) $oldQty <= $threshold;
            $pushToMeituanThis = $becameLow || $recovered;

            // 合并 5 秒内的推送请求
            $cacheKey = "inventory_push:{$inventory->room_type_id}";
            $cachedDates = Cache::get($cacheKey, []);
            $dateStr = $inventory->date->format('Y-m-d');

            if (!in_array($dateStr, $cachedDates)) {
                $cachedDates[] = $dateStr;
                Cache::put($cacheKey, $cachedDates, now()->addSeconds($pushDelay + 1));
            }

            if ($pushToMeituanThis) {
                Cache::put("inventory_push_meituan:{$inventory->room_type_id}", true, now()->addSeconds($pushDelay + 5));
            }

            // 只在一个日期首次变化时创建任务（避免重复创建）
            $taskKey = "inventory_push_task:{$inventory->room_type_id}";
            if (!Cache::has($taskKey)) {
                $pushToMeituan = Cache::pull("inventory_push_meituan:{$inventory->room_type_id}") ?? false;

                $job = PushChangedInventoryToOtaJob::dispatch(
                    $inventory->room_type_id,
                    array_unique($cachedDates),
                    null,
                    $pushToMeituan
                )->onQueue('ota-push');

                if ($pushDelay > 0) {
                    $job->delay(now()->addSeconds($pushDelay));
                }

                $taskTtl = $pushDelay > 0 ? $pushDelay : 1;
                Cache::put($taskKey, true, now()->addSeconds($taskTtl));

                Log::info('InventoryObserver：已调度OTA推送任务', [
                    'inventory_id' => $inventory->id,
                    'room_type_id' => $inventory->room_type_id,
                    'date' => $dateStr,
                    'dates' => array_unique($cachedDates),
                    'delay_seconds' => $pushDelay,
                    'push_to_meituan' => $pushToMeituan,
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

