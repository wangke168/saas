<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform as OtaPlatformEnum;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\ScenicSpotOtaInventoryPushConfig;
use Illuminate\Support\Facades\Cache;

class OtaInventoryPushConfigService
{
    /**
     * 获取推送到 OTA 时「视为 0」的库存阈值
     * 真实库存 ≤ 此值时，推送到 OTA 的库存为 0
     */
    public function getPushZeroThreshold(?int $scenicSpotId, string|OtaPlatformEnum|null $platform = null): int
    {
        $config = $this->getConfig($scenicSpotId, $platform);

        if ($config !== null) {
            return $config['push_zero_threshold'];
        }

        return (int) config('inventory.ota_push_zero_threshold', 0);
    }

    /**
     * @return array|null ['push_zero_threshold' => int]
     */
    protected function getConfig(?int $scenicSpotId, string|OtaPlatformEnum|null $platform): ?array
    {
        if ($scenicSpotId === null || $platform === null) {
            return null;
        }

        $code = $platform instanceof OtaPlatformEnum ? $platform->value : $platform;

        $cacheKey = "ota_inventory_push:{$scenicSpotId}:{$code}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($scenicSpotId, $code) {
            $platformModel = OtaPlatformModel::where('code', $code)->first();
            if (! $platformModel) {
                return null;
            }

            $row = ScenicSpotOtaInventoryPushConfig::query()
                ->where('scenic_spot_id', $scenicSpotId)
                ->where('ota_platform_id', $platformModel->id)
                ->where('is_active', true)
                ->first();

            if (! $row) {
                return null;
            }

            return [
                'push_zero_threshold' => (int) $row->push_zero_threshold,
            ];
        });
    }

    public function clearCache(int $scenicSpotId, int $otaPlatformId): void
    {
        $platform = OtaPlatformModel::find($otaPlatformId);
        if ($platform) {
            Cache::forget("ota_inventory_push:{$scenicSpotId}:{$platform->code->value}");
        }
    }
}
