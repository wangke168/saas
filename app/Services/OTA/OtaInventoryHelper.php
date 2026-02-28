<?php

namespace App\Services\OTA;

/**
 * OTA 库存推送辅助
 *
 * 统一规则：真实库存小于等于阈值时，推送到 OTA 的库存为 0。
 */
class OtaInventoryHelper
{
    /**
     * 推送到 OTA 时，真实库存小于等于此值则按 0 推送
     */
    private const OTA_ZERO_THRESHOLD = 2;

    /**
     * 获取「视为 0」的库存阈值（与 adjustQuantityForOta 一致，用于判断是否触发美团推送等）
     */
    public static function getZeroThreshold(): int
    {
        return self::OTA_ZERO_THRESHOLD;
    }

    /**
     * 根据真实库存计算推送到 OTA 的数量
     * 当真实库存 ≤ 2 时，推送到 OTA 为 0；否则为原值。
     *
     * @param int $realQuantity 真实库存（已考虑关闭、销售期、连续入住等）
     * @return int 推送到 OTA 的数量
     */
    public static function adjustQuantityForOta(int $realQuantity): int
    {
        return $realQuantity <= self::OTA_ZERO_THRESHOLD ? 0 : $realQuantity;
    }
}
