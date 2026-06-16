<?php

namespace App\Services\ExternalOrder;

use App\Models\ScenicSpotOrderPushConfig;
use Illuminate\Support\Facades\Cache;

class ExternalOrderPushConfigService
{
    private const CACHE_PREFIX = 'scenic_spot_order_push_enabled:';

    private const CACHE_TTL_SECONDS = 300;

    public function isEnabled(?int $scenicSpotId): bool
    {
        if ($scenicSpotId === null || $scenicSpotId <= 0) {
            return false;
        }

        return (bool) Cache::remember(
            self::CACHE_PREFIX.$scenicSpotId,
            self::CACHE_TTL_SECONDS,
            fn (): bool => ScenicSpotOrderPushConfig::query()
                ->where('scenic_spot_id', $scenicSpotId)
                ->where('enabled', true)
                ->exists()
        );
    }

    public function clearCache(int $scenicSpotId): void
    {
        Cache::forget(self::CACHE_PREFIX.$scenicSpotId);
    }
}
