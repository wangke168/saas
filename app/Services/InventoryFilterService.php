<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * 库存和价格变化过滤服务
 * 使用 Redis 快速比对，只处理真正变化的库存/价格
 */
class InventoryFilterService
{
    /**
     * 批量过滤变化的库存和价格
     * 
     * @param array $items 格式: [
     *   [
     *     'hotel_id' => 1,
     *     'room_type_id' => 1,
     *     'date' => '2025-12-27',
     *     'quantity' => 100,
     *     'price' => 1200.00,  // 可选，如果景区推送了价格
     *     'sale_price' => 1200.00,  // 可选
     *     'market_price' => 1500.00,  // 可选
     *     'settlement_price' => 1000.00,  // 可选
     *   ],
     *   ...
     * ]
     * @return array 变化的项目列表，格式相同，但只包含有变化的项
     */
    public function filterChanged(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        // 1. 批量构建 Redis Keys
        $keys = [];
        foreach ($items as $item) {
            $keys[] = $this->buildCacheKey(
                $item['hotel_id'],
                $item['room_type_id'],
                $item['date']
            );
        }

        // 2. 批量获取 Redis 缓存（MGET - 一次网络请求）
        try {
            $cachedValues = Redis::mget($keys);
        } catch (\Exception $e) {
            Log::error('InventoryFilterService: Redis MGET 失败', [
                'error' => $e->getMessage(),
                'keys_count' => count($keys),
            ]);
            // Redis 失败时，返回所有项（视为全部变化，确保数据不丢失）
            return $items;
        }
        
        // 3. 在 PHP 内存中比对（不访问数据库）
        $changed = [];
        $toUpdate = [];
        
        foreach ($items as $index => $item) {
            $key = $keys[$index];
            $cachedValue = $cachedValues[$index] ?? null;
            
            // 构建当前值的指纹
            $currentFingerprint = $this->buildFingerprint($item);
            
            // Redis 中没有或指纹不同，标记为变化
            if ($cachedValue === null || $cachedValue !== $currentFingerprint) {
                $changed[] = $item;
                $toUpdate[$key] = $currentFingerprint;
                
                Log::debug('检测到库存/价格变化', [
                    'key' => $key,
                    'old_value' => $cachedValue,
                    'new_value' => $currentFingerprint,
                    'hotel_id' => $item['hotel_id'],
                    'room_type_id' => $item['room_type_id'],
                    'date' => $item['date'],
                ]);
            }
        }

        // 4. 批量更新 Redis（MSET - 一次网络请求）
        if (!empty($toUpdate)) {
            try {
                // 使用 Pipeline 批量操作，提高性能
                $pipeline = Redis::pipeline();
                foreach ($toUpdate as $key => $value) {
                    $pipeline->setex($key, 7 * 24 * 3600, $value); // TTL: 7天
                }
                $pipeline->execute();
            } catch (\Exception $e) {
                Log::error('InventoryFilterService: Redis MSET 失败', [
                    'error' => $e->getMessage(),
                    'keys_count' => count($toUpdate),
                ]);
                // Redis 更新失败不影响业务逻辑，继续处理
            }
        }

        return $changed;
    }

    /**
     * 构建 Redis Key
     */
    protected function buildCacheKey(int $hotelId, int $roomTypeId, string $date): string
    {
        return "stock_price_cache:{$hotelId}:{$roomTypeId}:{$date}";
    }

    /**
     * 构建指纹字符串
     * 格式：库存|价格（如果价格存在）
     * 例如：100|1200.00 或 100|1200.00|1500.00|1000.00（包含市场价和结算价）
     */
    protected function buildFingerprint(array $item): string
    {
        $quantity = (int)($item['quantity'] ?? 0);
        
        // 如果提供了价格，包含价格信息
        if (isset($item['price']) || isset($item['sale_price'])) {
            $price = $item['price'] ?? $item['sale_price'] ?? 0;
            $fingerprint = "{$quantity}|{$price}";
            
            // 如果提供了市场价和结算价，也包含进去
            if (isset($item['market_price']) || isset($item['settlement_price'])) {
                $marketPrice = $item['market_price'] ?? '';
                $settlementPrice = $item['settlement_price'] ?? '';
                $fingerprint .= "|{$marketPrice}|{$settlementPrice}";
            }
            
            return $fingerprint;
        }
        
        // 只有库存，没有价格
        return (string)$quantity;
    }

    /**
     * 清除指定库存的缓存（用于手动刷新）
     */
    public function clearCache(int $hotelId, int $roomTypeId, ?string $date = null): void
    {
        try {
            if ($date) {
                // 清除指定日期的缓存
                $key = $this->buildCacheKey($hotelId, $roomTypeId, $date);
                Redis::del($key);
            } else {
                // 清除该房型所有日期的缓存（使用模式匹配）
                $pattern = $this->buildCacheKey($hotelId, $roomTypeId, '*');
                $keys = Redis::keys($pattern);
                if (!empty($keys)) {
                    Redis::del($keys);
                }
            }
        } catch (\Exception $e) {
            Log::error('InventoryFilterService: 清除缓存失败', [
                'error' => $e->getMessage(),
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'date' => $date,
            ]);
        }
    }
}

