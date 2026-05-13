<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform as OtaPlatformEnum;
use App\Models\OtaConfig;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\ScenicSpotOtaAccount;

/**
 * OTA 配置解析：平台级共用配置 + 景区级账号配置
 */
class OtaConfigResolver
{
    protected function isCtripScenicCredentialsRequired(): bool
    {
        return (bool) config('services.ctrip.scenic_credentials_required', false);
    }

    /**
     * 获取携程平台级配置（用于 Webhook 验签/解密，不区分景区）
     */
    public function getPlatformConfigForCtrip(): ?OtaConfig
    {
        $accountId = config('services.ctrip.account_id');
        $secretKey = config('services.ctrip.secret_key');
        if (! $accountId || ! $secretKey) {
            return null;
        }
        $config = new OtaConfig();
        $config->account = $accountId;
        $config->secret_key = $secretKey;
        $config->aes_key = config('services.ctrip.encrypt_key', '');
        $config->aes_iv = config('services.ctrip.encrypt_iv', '');
        $config->api_url = config('services.ctrip.price_api_url')
            ?: 'https://ttdopen.ctrip.com/api/product/price.do';
        $config->callback_url = config('services.ctrip.webhook_url', '');
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
        $appKey = config('services.meituan.app_key');
        $appSecret = config('services.meituan.app_secret');
        if (! $appKey || ! $appSecret) {
            return null;
        }
        $config = new OtaConfig();
        // 若未配置平台级 PartnerId，则置为 0；实际业务 PartnerId 以景区映射/请求为准
        $config->account = config('services.meituan.partner_id', 0);
        $config->secret_key = $appKey;
        $config->aes_key = $appSecret;
        $config->aes_iv = config('services.meituan.aes_key', '');
        $config->api_url = config('services.meituan.api_url', 'https://connectivity-adapter.meituan.com');
        $config->callback_url = config('services.meituan.webhook_url', '');
        $config->environment = 'production';
        $config->is_active = true;
        return $config;
    }

    protected function buildCtripConfigFromScenicAccount(ScenicSpotOtaAccount $accountConfig): ?OtaConfig
    {
        if (
            empty($accountConfig->account)
            || empty($accountConfig->secret_key)
            || empty($accountConfig->aes_key)
            || empty($accountConfig->aes_iv)
        ) {
            return null;
        }

        $config = new OtaConfig();
        $config->account = $accountConfig->account;
        $config->secret_key = $accountConfig->secret_key;
        $config->aes_key = $accountConfig->aes_key;
        $config->aes_iv = $accountConfig->aes_iv;
        $config->api_url = config('services.ctrip.price_api_url')
            ?: 'https://ttdopen.ctrip.com/api/product/price.do';
        $config->callback_url = config('services.ctrip.webhook_url', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 按景区获取携程配置（优先景区级四件套，必要时回退平台级）
     */
    public function getCtripConfigForScenicSpot(?int $scenicSpotId): ?OtaConfig
    {
        $platform = OtaPlatformModel::where('code', OtaPlatformEnum::CTRIP->value)->first();

        if ($platform && $scenicSpotId !== null) {
            $accountConfig = ScenicSpotOtaAccount::getConfigFor($scenicSpotId, $platform->id);
            if ($accountConfig) {
                $configFromAccount = $this->buildCtripConfigFromScenicAccount($accountConfig);
                if ($configFromAccount) {
                    return $configFromAccount;
                }

                if ($this->isCtripScenicCredentialsRequired()) {
                    return null;
                }
            }
        }

        return $this->getPlatformConfigForCtrip();
    }

    /**
     * 按携程账号获取配置（Webhook 验签/解密用）
     */
    public function getCtripConfigByAccount(string $account): ?OtaConfig
    {
        if ($account === '') {
            return null;
        }

        $platform = OtaPlatformModel::where('code', OtaPlatformEnum::CTRIP->value)->first();
        if ($platform) {
            $accountConfig = ScenicSpotOtaAccount::getConfigByAccount($platform->id, $account);
            if ($accountConfig) {
                $configFromAccount = $this->buildCtripConfigFromScenicAccount($accountConfig);
                if ($configFromAccount) {
                    return $configFromAccount;
                }

                if ($this->isCtripScenicCredentialsRequired()) {
                    return null;
                }
            }
        }

        $platformConfig = $this->getPlatformConfigForCtrip();
        if ($platformConfig && $platformConfig->account === $account) {
            return $platformConfig;
        }

        return null;
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
