<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform as OtaPlatformEnum;

/**
 * OTA 库存推送辅助
 *
 * 统一规则：真实库存小于等于阈值时，推送到 OTA 的库存为 0。
 * 阈值可按景区+平台配置，未配置时使用 config/inventory.php 全局默认值（默认 0）。
 */
class OtaInventoryHelper
{
    public static function getZeroThreshold(
        ?int $scenicSpotId = null,
        string|OtaPlatformEnum|null $platform = null
    ): int {
        return app(OtaInventoryPushConfigService::class)->getPushZeroThreshold($scenicSpotId, $platform);
    }

    /**
     * @param int $realQuantity 真实库存（已考虑关闭、销售期、连续入住等）
     */
    public static function adjustQuantityForOta(
        int $realQuantity,
        ?int $scenicSpotId = null,
        string|OtaPlatformEnum|null $platform = null
    ): int {
        $threshold = self::getZeroThreshold($scenicSpotId, $platform);

        return $realQuantity <= $threshold ? 0 : $realQuantity;
    }
}
