<?php

namespace App\Services;

use App\Models\Inventory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class InventoryService
{
    /**
     * 锁定库存（支持单天）
     *
     * @param int $roomTypeId 房型ID
     * @param string $date 日期 (Y-m-d)
     * @param int $quantity 锁定数量
     * @return bool 是否锁定成功
     */
    public function lockInventory(int $roomTypeId, string $date, int $quantity): bool
    {
        return $this->lockInventoryForDates($roomTypeId, [$date], $quantity);
    }

    /**
     * 锁定库存（支持多天）
     *
     * @param int $roomTypeId 房型ID
     * @param array $dates 日期数组 (Y-m-d)
     * @param int $quantity 锁定数量
     * @return bool 是否锁定成功
     */
    public function lockInventoryForDates(int $roomTypeId, array $dates, int $quantity): bool
    {
        // 生成锁键
        $lockKeys = [];
        foreach ($dates as $date) {
            $lockKeys[] = "inventory_lock:{$roomTypeId}:{$date}";
        }

        // 获取分布式锁
        $acquiredLocks = [];
        foreach ($lockKeys as $lockKey) {
            $lock = Redis::set($lockKey, 1, 'EX', 30, 'NX');
            if (!$lock) {
                // 获取锁失败，释放已获取的锁
                foreach ($acquiredLocks as $acquiredLock) {
                    Redis::del($acquiredLock);
                }
                Log::warning('库存锁定失败：获取分布式锁失败', [
                    'room_type_id' => $roomTypeId,
                    'dates' => $dates,
                    'quantity' => $quantity,
                ]);
                return false;
            }
            $acquiredLocks[] = $lockKey;
        }

        try {
            // 检查库存可用性
            if (!$this->checkInventoryAvailability($roomTypeId, $dates, $quantity)) {
                Log::warning('库存锁定失败：库存不足', [
                    'room_type_id' => $roomTypeId,
                    'dates' => $dates,
                    'quantity' => $quantity,
                ]);
                return false;
            }

            // 锁定库存
            foreach ($dates as $date) {
                $inventory = Inventory::where('room_type_id', $roomTypeId)
                    ->where('date', $date)
                    ->first();

                if (!$inventory) {
                    Log::error('库存锁定失败：库存记录不存在', [
                        'room_type_id' => $roomTypeId,
                        'date' => $date,
                    ]);
                    return false;
                }

                $inventory->available_quantity -= $quantity;
                $inventory->locked_quantity += $quantity;
                $inventory->save();
            }

            Log::info('库存锁定成功', [
                'room_type_id' => $roomTypeId,
                'dates' => $dates,
                'quantity' => $quantity,
            ]);

            return true;
        } finally {
            // 释放锁
            foreach ($acquiredLocks as $lockKey) {
                Redis::del($lockKey);
            }
        }
    }

    /**
     * 释放库存（支持单天）
     *
     * @param int $roomTypeId 房型ID
     * @param string $date 日期 (Y-m-d)
     * @param int $quantity 释放数量
     * @return bool 是否释放成功
     */
    public function releaseInventory(int $roomTypeId, string $date, int $quantity): bool
    {
        return $this->releaseInventoryForDates($roomTypeId, [$date], $quantity);
    }

    /**
     * 释放库存（支持多天）
     *
     * @param int $roomTypeId 房型ID
     * @param array $dates 日期数组 (Y-m-d)
     * @param int $quantity 释放数量
     * @return bool 是否释放成功
     */
    public function releaseInventoryForDates(int $roomTypeId, array $dates, int $quantity): bool
    {
        // 生成锁键
        $lockKeys = [];
        foreach ($dates as $date) {
            $lockKeys[] = "inventory_lock:{$roomTypeId}:{$date}";
        }

        // 获取分布式锁
        $acquiredLocks = [];
        foreach ($lockKeys as $lockKey) {
            $lock = Redis::set($lockKey, 1, 'EX', 30, 'NX');
            if (!$lock) {
                // 获取锁失败，释放已获取的锁
                foreach ($acquiredLocks as $acquiredLock) {
                    Redis::del($acquiredLock);
                }
                Log::warning('库存释放失败：获取分布式锁失败', [
                    'room_type_id' => $roomTypeId,
                    'dates' => $dates,
                    'quantity' => $quantity,
                ]);
                return false;
            }
            $acquiredLocks[] = $lockKey;
        }

        try {
            // 释放库存
            foreach ($dates as $date) {
                $inventory = Inventory::where('room_type_id', $roomTypeId)
                    ->where('date', $date)
                    ->first();

                if (!$inventory) {
                    Log::error('库存释放失败：库存记录不存在', [
                        'room_type_id' => $roomTypeId,
                        'date' => $date,
                    ]);
                    return false;
                }

                // 确保释放数量不超过锁定数量
                $releaseQuantity = min($quantity, $inventory->locked_quantity);
                $inventory->available_quantity += $releaseQuantity;
                $inventory->locked_quantity -= $releaseQuantity;
                $inventory->save();
            }

            Log::info('库存释放成功', [
                'room_type_id' => $roomTypeId,
                'dates' => $dates,
                'quantity' => $quantity,
            ]);

            return true;
        } finally {
            // 释放锁
            foreach ($acquiredLocks as $lockKey) {
                Redis::del($lockKey);
            }
        }
    }

    /**
     * 检查库存可用性
     *
     * @param int $roomTypeId 房型ID
     * @param array $dates 日期数组 (Y-m-d)
     * @param int $quantity 需要数量
     * @return bool 是否可用
     */
    public function checkInventoryAvailability(int $roomTypeId, array $dates, int $quantity): bool
    {
        foreach ($dates as $date) {
            $inventory = Inventory::where('room_type_id', $roomTypeId)
                ->where('date', $date)
                ->first();

            if (!$inventory || $inventory->is_closed || $inventory->available_quantity < $quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * 获取连续日期数组
     *
     * @param string $startDate 开始日期 (Y-m-d)
     * @param int $days 天数
     * @return array 日期数组
     */
    public function getDateRange(string $startDate, int $days): array
    {
        $dates = [];
        $date = Carbon::parse($startDate);

        for ($i = 0; $i < $days; $i++) {
            $dates[] = $date->copy()->addDays($i)->format('Y-m-d');
        }

        return $dates;
    }
}
