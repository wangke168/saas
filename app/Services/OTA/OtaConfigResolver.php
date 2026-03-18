<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform as OtaPlatformEnum;
use App\Models\OtaConfig;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\ScenicSpotOtaAccount;

/**
 * OTA 配置解析：平台级共用配置 + 景区级 account 映射
 * 仅 account/partnerId 按景区区分，其余从 .env 平台级读取
 */
class OtaConfigResolver
{
    /**
     * 获取携程平台级配置（用于 Webhook 验签/解密，不区分景区）
     */
    public function getPlatformConfigForCtrip(): ?OtaConfig
    {
        if (!env('CTRIP_ACCOUNT_ID') || !env('CTRIP_SECRET_KEY')) {
            return null;
        }
        $config = new OtaConfig();
        $config->account = env('CTRIP_ACCOUNT_ID');
        $config->secret_key = env('CTRIP_SECRET_KEY');
        $config->aes_key = env('CTRIP_ENCRYPT_KEY', '');
        $config->aes_iv = env('CTRIP_ENCRYPT_IV', '');
        $config->api_url = env('CTRIP_PRICE_API_URL', 'https://ttdopen.ctrip.com/api/product/price.do');
        $config->callback_url = env('CTRIP_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;
        return $config;
    }

    /**
     * 获取美团平台级配置（用于 Webhook 解密，不区分景区）
     */
    public function getPlatformConfigForMeituan(): ?OtaConfig
    {
        // 注意：PartnerId 是景区级概念，不强依赖平台级 .env 配置；
        // 平台级配置主要用于 Webhook 验签/加解密（密钥级别共用）。
        if (!env('MEITUAN_APP_KEY') || !env('MEITUAN_APP_SECRET')) {
            return null;
        }
        $config = new OtaConfig();
        // 若未配置平台级 PartnerId，则置为 0；实际业务 PartnerId 以景区映射/请求为准
        $config->account = env('MEITUAN_PARTNER_ID', 0);
        $config->secret_key = env('MEITUAN_APP_KEY');
        $config->aes_key = env('MEITUAN_APP_SECRET');
        $config->aes_iv = env('MEITUAN_AES_KEY', '');
        $config->api_url = env('MEITUAN_API_URL', 'https://connectivity-adapter.meituan.com');
        $config->callback_url = env('MEITUAN_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;
        return $config;
    }

    /**
     * 按景区获取携程配置（平台级 + 映射表覆盖 account）
     */
    public function getCtripConfigForScenicSpot(?int $scenicSpotId): ?OtaConfig
    {
        $config = $this->getPlatformConfigForCtrip();
        if (!$config) {
            return null;
        }
        $platform = OtaPlatformModel::where('code', OtaPlatformEnum::CTRIP->value)->first();
        if ($platform && $scenicSpotId !== null) {
            $account = ScenicSpotOtaAccount::getAccountFor($scenicSpotId, $platform->id);
            if ($account !== null) {
                $config->account = $account;
            }
        }
        return $config;
    }

    /**
     * 按景区获取美团配置（平台级 + 映射表覆盖 account）
     */
    public function getMeituanConfigForScenicSpot(?int $scenicSpotId): ?OtaConfig
    {
        $config = $this->getPlatformConfigForMeituan();
        if (!$config) {
            return null;
        }
        $platform = OtaPlatformModel::where('code', OtaPlatformEnum::MEITUAN->value)->first();
        if ($platform && $scenicSpotId !== null) {
            $account = ScenicSpotOtaAccount::getAccountFor($scenicSpotId, $platform->id);
            if ($account !== null) {
                $config->account = $account;
            }
        }
        return $config;
    }

    /**
     * 按平台 + 景区获取配置（统一入口）
     * @param string|OtaPlatformEnum $platform 平台 code 或枚举
     * @param int|null $scenicSpotId 景区 ID，null 时使用平台默认 account
     */
    public function getConfigForScenicSpot(string|OtaPlatformEnum $platform, ?int $scenicSpotId): ?OtaConfig
    {
        $code = $platform instanceof OtaPlatformEnum ? $platform->value : $platform;
        return match ($code) {
            OtaPlatformEnum::CTRIP->value => $this->getCtripConfigForScenicSpot($scenicSpotId),
            OtaPlatformEnum::MEITUAN->value => $this->getMeituanConfigForScenicSpot($scenicSpotId),
            default => null,
        };
    }

    /**
     * 按平台 + account 反查景区 ID（Webhook 路由用）
     */
    public function getScenicSpotIdByAccount(int $otaPlatformId, string $account): ?int
    {
        return ScenicSpotOtaAccount::getScenicSpotIdByAccount($otaPlatformId, $account);
    }

    /**
     * 获取携程平台 ID
     */
    public function getCtripPlatformId(): ?int
    {
        $p = OtaPlatformModel::where('code', OtaPlatformEnum::CTRIP->value)->first();
        return $p?->id;
    }

    /**
     * 获取美团平台 ID
     */
    public function getMeituanPlatformId(): ?int
    {
        $p = OtaPlatformModel::where('code', OtaPlatformEnum::MEITUAN->value)->first();
        return $p?->id;
    }
}
