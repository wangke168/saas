<?php

namespace App\Services;

use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Redis\Connections\ConnectionException;
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
        // 尝试使用 Redis 分布式锁
        try {
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
                        try {
                            Redis::del($acquiredLock);
                        } catch (\Exception $e) {
                            // 忽略释放锁的异常
                        }
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

                // 锁定库存（使用数据库行级锁）
                DB::transaction(function () use ($roomTypeId, $dates, $quantity) {
                    foreach ($dates as $date) {
                        $inventory = Inventory::where('room_type_id', $roomTypeId)
                            ->where('date', $date)
                            ->lockForUpdate() // 数据库行级锁
                            ->first();

                        if (!$inventory) {
                            throw new \Exception("库存记录不存在：room_type_id={$roomTypeId}, date={$date}");
                        }

                        // 修复：确保锁定后 available_quantity 不会小于 0，且不超过 total_quantity
                        $newAvailableQuantity = max(0, $inventory->available_quantity - $quantity);
                        $inventory->available_quantity = min($newAvailableQuantity, $inventory->total_quantity);
                        $inventory->locked_quantity += $quantity;
                        $inventory->save();
                    }
                });

                Log::info('库存锁定成功（Redis锁）', [
                    'room_type_id' => $roomTypeId,
                    'dates' => $dates,
                    'quantity' => $quantity,
                ]);

                return true;
            } finally {
                // 释放 Redis 锁
                foreach ($acquiredLocks as $lockKey) {
                    try {
                        Redis::del($lockKey);
                    } catch (\Exception $e) {
                        // 忽略释放锁的异常
                    }
                }
            }
        } catch (ConnectionException $e) {
            // Redis 连接失败，使用数据库锁降级方案
            Log::warning('Redis 连接失败，使用数据库锁降级方案', [
                'error' => $e->getMessage(),
                'room_type_id' => $roomTypeId,
                'dates' => $dates,
                'quantity' => $quantity,
            ]);

            return $this->lockInventoryWithDatabaseLock($roomTypeId, $dates, $quantity);
        } catch (\Exception $e) {
            Log::error('库存锁定异常', [
                'error' => $e->getMessage(),
                'room_type_id' => $roomTypeId,
                'dates' => $dates,
                'quantity' => $quantity,
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
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
        // 尝试使用 Redis 分布式锁
        try {
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
                        try {
                            Redis::del($acquiredLock);
                        } catch (\Exception $e) {
                            // 忽略释放锁的异常
                        }
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
                // 释放库存（使用数据库行级锁）
                DB::transaction(function () use ($roomTypeId, $dates, $quantity) {
                    foreach ($dates as $date) {
                        $inventory = Inventory::where('room_type_id', $roomTypeId)
                            ->where('date', $date)
                            ->lockForUpdate() // 数据库行级锁
                            ->first();

                        if (!$inventory) {
                            throw new \Exception("库存记录不存在：room_type_id={$roomTypeId}, date={$date}");
                        }

                        // 确保释放数量不超过锁定数量
                        $releaseQuantity = min($quantity, $inventory->locked_quantity);
                        $inventory->locked_quantity -= $releaseQuantity;
                        // 修复：确保 available_quantity 不超过 total_quantity
                        $newAvailableQuantity = $inventory->available_quantity + $releaseQuantity;
                        $inventory->available_quantity = min($newAvailableQuantity, $inventory->total_quantity);
                        $inventory->save();
                    }
                });

                Log::info('库存释放成功（Redis锁）', [
                    'room_type_id' => $roomTypeId,
                    'dates' => $dates,
                    'quantity' => $quantity,
                ]);

                return true;
            } finally {
                // 释放 Redis 锁
                foreach ($acquiredLocks as $lockKey) {
                    try {
                        Redis::del($lockKey);
                    } catch (\Exception $e) {
                        // 忽略释放锁的异常
                    }
                }
            }
        } catch (ConnectionException $e) {
            // Redis 连接失败，使用数据库锁降级方案
            Log::warning('Redis 连接失败，使用数据库锁降级方案', [
                'error' => $e->getMessage(),
                'room_type_id' => $roomTypeId,
                'dates' => $dates,
                'quantity' => $quantity,
            ]);

            return $this->releaseInventoryWithDatabaseLock($roomTypeId, $dates, $quantity);
        } catch (\Exception $e) {
            Log::error('库存释放异常', [
                'error' => $e->getMessage(),
                'room_type_id' => $roomTypeId,
                'dates' => $dates,
                'quantity' => $quantity,
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
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

    /**
     * 使用数据库锁锁定库存（降级方案）
     * 当 Redis 不可用时使用此方法
     *
     * @param int $roomTypeId 房型ID
     * @param array $dates 日期数组 (Y-m-d)
     * @param int $quantity 锁定数量
     * @return bool 是否锁定成功
     */
    protected function lockInventoryWithDatabaseLock(int $roomTypeId, array $dates, int $quantity): bool
    {
        try {
            return DB::transaction(function () use ($roomTypeId, $dates, $quantity) {
                // 检查库存可用性
                foreach ($dates as $date) {
                    $inventory = Inventory::where('room_type_id', $roomTypeId)
                        ->where('date', $date)
                        ->lockForUpdate() // 数据库行级锁
                        ->first();

                    if (!$inventory || $inventory->is_closed || $inventory->available_quantity < $quantity) {
                        Log::warning('库存锁定失败（数据库锁）：库存不足', [
                            'room_type_id' => $roomTypeId,
                            'date' => $date,
                            'quantity' => $quantity,
                            'available_quantity' => $inventory->available_quantity ?? 0,
                            'is_closed' => $inventory->is_closed ?? false,
                        ]);
                        return false;
                    }
                }

                // 锁定库存
                foreach ($dates as $date) {
                    $inventory = Inventory::where('room_type_id', $roomTypeId)
                        ->where('date', $date)
                        ->lockForUpdate()
                        ->first();

                    $inventory->available_quantity -= $quantity;
                    $inventory->locked_quantity += $quantity;
                    $inventory->save();
                }

                Log::info('库存锁定成功（数据库锁降级）', [
                    'room_type_id' => $roomTypeId,
                    'dates' => $dates,
                    'quantity' => $quantity,
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('数据库锁库存锁定失败', [
                'error' => $e->getMessage(),
                'room_type_id' => $roomTypeId,
                'dates' => $dates,
                'quantity' => $quantity,
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * 使用数据库锁释放库存（降级方案）
     * 当 Redis 不可用时使用此方法
     *
     * @param int $roomTypeId 房型ID
     * @param array $dates 日期数组 (Y-m-d)
     * @param int $quantity 释放数量
     * @return bool 是否释放成功
     */
    protected function releaseInventoryWithDatabaseLock(int $roomTypeId, array $dates, int $quantity): bool
    {
        try {
            return DB::transaction(function () use ($roomTypeId, $dates, $quantity) {
                // 释放库存
                foreach ($dates as $date) {
                    $inventory = Inventory::where('room_type_id', $roomTypeId)
                        ->where('date', $date)
                        ->lockForUpdate() // 数据库行级锁
                        ->first();

                    if (!$inventory) {
                        Log::error('库存释放失败（数据库锁）：库存记录不存在', [
                            'room_type_id' => $roomTypeId,
                            'date' => $date,
                        ]);
                        return false;
                    }

                    // 确保释放数量不超过锁定数量
                    $releaseQuantity = min($quantity, $inventory->locked_quantity);
                    $inventory->locked_quantity -= $releaseQuantity;
                    // 修复：确保 available_quantity 不超过 total_quantity
                    $newAvailableQuantity = $inventory->available_quantity + $releaseQuantity;
                    $inventory->available_quantity = min($newAvailableQuantity, $inventory->total_quantity);
                    $inventory->save();
                }

                Log::info('库存释放成功（数据库锁降级）', [
                    'room_type_id' => $roomTypeId,
                    'dates' => $dates,
                    'quantity' => $quantity,
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('数据库锁库存释放失败', [
                'error' => $e->getMessage(),
                'room_type_id' => $roomTypeId,
                'dates' => $dates,
                'quantity' => $quantity,
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
}
