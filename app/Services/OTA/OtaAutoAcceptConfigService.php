<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform as OtaPlatformEnum;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\ScenicSpotOtaAutoAcceptConfig;
use Illuminate\Support\Facades\Cache;

class OtaAutoAcceptConfigService
{
    /**
     * 是否启用“库存充裕自动接单”
     */
    public function isAutoAcceptEnabled(?int $scenicSpotId, string|OtaPlatformEnum $platform): bool
    {
        $config = $this->getConfig($scenicSpotId, $platform);

        if ($config !== null) {
            return $config['auto_accept_when_sufficient'];
        }

        $code = $platform instanceof OtaPlatformEnum ? $platform->value : $platform;

        return match ($code) {
            OtaPlatformEnum::MEITUAN->value => filter_var(env('MEITUAN_AUTO_ACCEPT_WHEN_SUFFICIENT', true), FILTER_VALIDATE_BOOLEAN),
            OtaPlatformEnum::CTRIP->value => filter_var(env('CTRIP_AUTO_ACCEPT_WHEN_SUFFICIENT', true), FILTER_VALIDATE_BOOLEAN),
            default => true,
        };
    }

    /**
     * 获取“库存充裕缓冲值”
     */
    public function getAutoAcceptBuffer(?int $scenicSpotId, string|OtaPlatformEnum $platform): int
    {
        $config = $this->getConfig($scenicSpotId, $platform);

        if ($config !== null) {
            return $config['auto_accept_stock_buffer'];
        }

        $code = $platform instanceof OtaPlatformEnum ? $platform->value : $platform;

        return match ($code) {
            OtaPlatformEnum::MEITUAN->value => (int) env('MEITUAN_AUTO_ACCEPT_STOCK_BUFFER', 5),
            OtaPlatformEnum::CTRIP->value => (int) env('CTRIP_AUTO_ACCEPT_STOCK_BUFFER', 5),
            default => 5,
        };
    }

    /**
     * 读取景区级 OTA 自动接单配置（带简单缓存）
     *
     * @return array|null ['auto_accept_when_sufficient' => bool, 'auto_accept_stock_buffer' => int]
     */
    protected function getConfig(?int $scenicSpotId, string|OtaPlatformEnum $platform): ?array
    {
        if ($scenicSpotId === null) {
            return null;
        }

        $code = $platform instanceof OtaPlatformEnum ? $platform->value : $platform;

        $cacheKey = "ota_auto_accept:{$scenicSpotId}:{$code}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($scenicSpotId, $code) {
            $platformModel = OtaPlatformModel::where('code', $code)->first();
            if (!$platformModel) {
                return null;
            }

            $row = ScenicSpotOtaAutoAcceptConfig::query()
                ->where('scenic_spot_id', $scenicSpotId)
                ->where('ota_platform_id', $platformModel->id)
                ->where('is_active', true)
                ->first();

            if (!$row) {
                return null;
            }

            return [
                'auto_accept_when_sufficient' => (bool) $row->auto_accept_when_sufficient,
                'auto_accept_stock_buffer' => (int) $row->auto_accept_stock_buffer,
            ];
        });
    }
}

