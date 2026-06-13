<?php

namespace App\Support;

final class WechatEnv
{
    public static function value(string $key, string $default = ''): string
    {
        $raw = env($key, $default);
        if (! is_string($raw) && ! is_numeric($raw)) {
            return $default;
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return $default;
        }

        // 兼容 .env 行内注释：1746494139        # 商户号
        if (preg_match('/\s+#/', $value) === 1) {
            $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
        }

        return $value;
    }

    public static function path(string $key, string $default = ''): string
    {
        $path = self::value($key, $default);
        if ($path === '') {
            return '';
        }

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        return $path;
    }

    /**
     * 微信支付公钥模式：serial => pem 路径（新商户无 /v3/certificates 平台证书时需配置）
     *
     * @return array<string, string>
     */
    public static function wechatPublicCertMap(): array
    {
        $serial = self::value('WECHAT_PAY_PUBLIC_KEY_ID');
        $path = self::path('WECHAT_PAY_PUBLIC_KEY_PATH');

        if ($serial === '' || $path === '' || ! is_file($path)) {
            return [];
        }

        return [$serial => $path];
    }
}
