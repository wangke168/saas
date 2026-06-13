<?php

namespace App\Services\Mp;

use App\Enums\FulfillmentMode;
use App\Enums\OrderEntitlementStatus;
use App\Exceptions\Sms\SmsRateLimitException;
use App\Models\Order;
use App\Services\Sms\AliyunSmsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MpAuthService
{
    private const SMS_PREFIX = 'mp:sms:';

    private const SMS_RESEND_PREFIX = 'mp:sms:resend:';

    private const SMS_DAILY_PHONE_PREFIX = 'mp:sms:daily:phone:';

    private const SMS_DAILY_IP_PREFIX = 'mp:sms:daily:ip:';

    private const TOKEN_PREFIX = 'mp:token:';

    private const TOKEN_TTL_SECONDS = 86400 * 30;

    public function __construct(
        private readonly AliyunSmsService $aliyunSmsService,
    ) {}

    public function normalizeLoginPhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', trim($phone)) ?? '';
        if (str_starts_with($phone, '+86')) {
            $phone = substr($phone, 3);
        } elseif (str_starts_with($phone, '86') && strlen($phone) === 13) {
            $phone = substr($phone, 2);
        }

        return $phone;
    }

    /**
     * 是否允许登录小程序：OTA 购票手机号 + 存在预售父单且至少有权益记录。
     * 含待预约、预约中、已预约，便于查看历史预约；不要求仍有 pending 权益。
     */
    public function canLoginForBooking(string $phone): bool
    {
        $phone = $this->normalizeLoginPhone($phone);

        return Order::query()
            ->where('contact_phone', $phone)
            ->whereNull('parent_order_id')
            ->whereHas('product', function ($query): void {
                $query->where('fulfillment_mode', FulfillmentMode::Deferred->value);
            })
            ->whereHas('entitlements')
            ->exists();
    }

    /**
     * 是否仍有待预约权益（用于 C 端提示，不影响登录）。
     */
    public function hasPendingEntitlement(string $phone): bool
    {
        $phone = $this->normalizeLoginPhone($phone);

        return Order::query()
            ->where('contact_phone', $phone)
            ->whereNull('parent_order_id')
            ->whereHas('product', function ($query): void {
                $query->where('fulfillment_mode', FulfillmentMode::Deferred->value);
            })
            ->whereHas('entitlements', function ($query): void {
                $query->where('status', OrderEntitlementStatus::Pending);
            })
            ->exists();
    }

    public function sendSmsCode(string $phone, ?string $ip = null): string
    {
        $phone = $this->normalizeLoginPhone($phone);
        $this->assertSmsRateLimit($phone, $ip);

        $code = (string) random_int(100000, 999999);
        $this->aliyunSmsService->sendVerificationCode($phone, $code);

        Cache::put($this->smsKey($phone), $code, $this->smsTtlSeconds());
        $this->recordSmsRateLimit($phone, $ip);

        return $code;
    }

    public function verifySmsCode(string $phone, string $code): bool
    {
        $phone = $this->normalizeLoginPhone($phone);
        $cachedCode = Cache::get($this->smsKey($phone));
        if (! is_string($cachedCode) || $cachedCode !== $code) {
            return false;
        }

        Cache::forget($this->smsKey($phone));

        return true;
    }

    public function issueToken(string $phone, ?string $openid = null): string
    {
        $phone = $this->normalizeLoginPhone($phone);
        $token = Str::random(64);
        $payload = ['phone' => $phone];
        if ($openid !== null && $openid !== '') {
            $payload['openid'] = $openid;
        }
        Cache::put($this->tokenKey($token), $payload, self::TOKEN_TTL_SECONDS);

        return $token;
    }

    /**
     * @return array{phone: string, openid?: string}|null
     */
    public function resolveTokenPayload(?string $token): ?array
    {
        if (! $token) {
            return null;
        }

        $payload = Cache::get($this->tokenKey($token));
        if (! is_array($payload) || ! isset($payload['phone'])) {
            return null;
        }

        $result = ['phone' => (string) $payload['phone']];
        if (! empty($payload['openid'])) {
            $result['openid'] = (string) $payload['openid'];
        }

        return $result;
    }

    public function resolvePhoneFromToken(?string $token): ?string
    {
        $payload = $this->resolveTokenPayload($token);

        return $payload['phone'] ?? null;
    }

    public function resolveOpenidFromToken(?string $token): ?string
    {
        $payload = $this->resolveTokenPayload($token);

        return $payload['openid'] ?? null;
    }

    public function bindOpenidToToken(string $token, string $openid): void
    {
        $payload = $this->resolveTokenPayload($token);
        if ($payload === null || $openid === '') {
            return;
        }

        $payload['openid'] = $openid;
        Cache::put($this->tokenKey($token), $payload, self::TOKEN_TTL_SECONDS);
    }

    private function assertSmsRateLimit(string $phone, ?string $ip): void
    {
        if (Cache::has($this->smsResendKey($phone))) {
            throw new SmsRateLimitException('发送过于频繁，请稍后再试');
        }

        $phoneDailyLimit = (int) config('sms.mp_login.daily_limit_per_phone', 10);
        $phoneDailyCount = (int) Cache::get($this->smsDailyPhoneKey($phone), 0);
        if ($phoneDailyCount >= $phoneDailyLimit) {
            throw new SmsRateLimitException('今日验证码发送次数已达上限');
        }

        if ($ip === null || $ip === '') {
            return;
        }

        $ipDailyLimit = (int) config('sms.mp_login.daily_limit_per_ip', 30);
        $ipDailyCount = (int) Cache::get($this->smsDailyIpKey($ip), 0);
        if ($ipDailyCount >= $ipDailyLimit) {
            throw new SmsRateLimitException('今日验证码发送次数已达上限');
        }
    }

    private function recordSmsRateLimit(string $phone, ?string $ip): void
    {
        $resendInterval = (int) config('sms.mp_login.resend_interval_seconds', 60);
        Cache::put($this->smsResendKey($phone), 1, $resendInterval);

        $secondsUntilMidnight = max(1, now()->endOfDay()->diffInSeconds(now()));
        $phoneKey = $this->smsDailyPhoneKey($phone);
        Cache::put($phoneKey, (int) Cache::get($phoneKey, 0) + 1, $secondsUntilMidnight);

        if ($ip === null || $ip === '') {
            return;
        }

        $ipKey = $this->smsDailyIpKey($ip);
        Cache::put($ipKey, (int) Cache::get($ipKey, 0) + 1, $secondsUntilMidnight);
    }

    private function smsTtlSeconds(): int
    {
        return (int) config('sms.mp_login.code_ttl_seconds', 300);
    }

    private function smsKey(string $phone): string
    {
        return self::SMS_PREFIX.$phone;
    }

    private function smsResendKey(string $phone): string
    {
        return self::SMS_RESEND_PREFIX.$phone;
    }

    private function smsDailyPhoneKey(string $phone): string
    {
        return self::SMS_DAILY_PHONE_PREFIX.$phone.':'.now()->toDateString();
    }

    private function smsDailyIpKey(string $ip): string
    {
        return self::SMS_DAILY_IP_PREFIX.$ip.':'.now()->toDateString();
    }

    private function tokenKey(string $token): string
    {
        return self::TOKEN_PREFIX.$token;
    }
}
