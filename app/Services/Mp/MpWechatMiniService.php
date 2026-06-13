<?php

namespace App\Services\Mp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 微信小程序：code2session、手机号快速验证组件取号。
 */
final class MpWechatMiniService
{
    private const ACCESS_TOKEN_CACHE_KEY = 'wechat:mini:access_token';

    private const ACCESS_TOKEN_TTL_SECONDS = 7000;

    /**
     * @return array{openid: string, session_key: string, unionid?: string}
     */
    public function codeToSession(string $loginCode): array
    {
        $this->assertConfigured();

        $response = Http::timeout(10)->get('https://api.weixin.qq.com/sns/jscode2session', [
            'appid' => $this->appId(),
            'secret' => $this->appSecret(),
            'js_code' => $loginCode,
            'grant_type' => 'authorization_code',
        ]);

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('微信登录响应无效');
        }

        if (isset($data['errcode']) && (int) $data['errcode'] !== 0) {
            Log::warning('微信 jscode2session 失败', ['response' => $data]);

            throw new \RuntimeException('微信登录失败：'.($data['errmsg'] ?? '未知错误'));
        }

        $openid = (string) ($data['openid'] ?? '');
        if ($openid === '') {
            throw new \RuntimeException('微信登录未返回 openid');
        }

        return [
            'openid' => $openid,
            'session_key' => (string) ($data['session_key'] ?? ''),
            'unionid' => isset($data['unionid']) ? (string) $data['unionid'] : null,
        ];
    }

    /**
     * 手机号快速验证组件 code → 纯手机号（国内一般为 11 位，可能带 +86）。
     */
    public function getPhoneByCode(string $phoneCode): string
    {
        $this->assertConfigured();

        $accessToken = $this->getAccessToken();
        $response = Http::timeout(10)->post(
            'https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token='.$accessToken,
            ['code' => $phoneCode],
        );

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('微信取号响应无效');
        }

        if ((int) ($data['errcode'] ?? -1) !== 0) {
            Log::warning('微信 getuserphonenumber 失败', ['response' => $data]);

            throw new \RuntimeException('获取微信手机号失败：'.($data['errmsg'] ?? '未知错误'));
        }

        $phone = (string) ($data['phone_info']['phoneNumber'] ?? $data['phone_info']['purePhoneNumber'] ?? '');
        if ($phone === '') {
            throw new \RuntimeException('微信未返回手机号');
        }

        return $phone;
    }

    public function isConfigured(): bool
    {
        return $this->appId() !== '' && $this->appSecret() !== '';
    }

    protected function getAccessToken(): string
    {
        $cached = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::timeout(10)->get('https://api.weixin.qq.com/cgi-bin/token', [
            'grant_type' => 'client_credential',
            'appid' => $this->appId(),
            'secret' => $this->appSecret(),
        ]);

        $data = $response->json();
        if (! is_array($data) || empty($data['access_token'])) {
            Log::error('微信 access_token 获取失败', ['response' => $data]);

            throw new \RuntimeException('微信服务暂不可用，请使用验证码登录');
        }

        $token = (string) $data['access_token'];
        Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $token, self::ACCESS_TOKEN_TTL_SECONDS);

        return $token;
    }

    protected function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('微信小程序未配置，请使用验证码登录');
        }
    }

    protected function appId(): string
    {
        return (string) config('wechat.mini_program.app_id', '');
    }

    protected function appSecret(): string
    {
        return (string) config('wechat.mini_program.app_secret', '');
    }
}
