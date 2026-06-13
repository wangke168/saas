<?php

namespace App\Http\Controllers\Mp;

use App\Enums\FulfillmentMode;
use App\Exceptions\Sms\SmsRateLimitException;
use App\Exceptions\Sms\SmsSendFailedException;
use App\Models\Order;
use App\Services\Mp\MpAuthService;
use App\Services\Mp\MpWechatMiniService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 小程序 C 端鉴权：微信手机号快捷登录 + 短信验证码备用。
 */
class AuthController extends BaseMpController
{
    public function __construct(
        private readonly MpAuthService $mpAuthService,
        private readonly MpWechatMiniService $wechatMiniService,
    ) {}

    public function sendSms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'regex:/^1\d{10}$/'],
        ]);

        $phone = $this->mpAuthService->normalizeLoginPhone((string) $validated['phone']);

        if (! $this->mpAuthService->canLoginForBooking($phone)) {
            return $this->loginDeniedResponse($phone);
        }

        try {
            $code = $this->mpAuthService->sendSmsCode($phone, $request->ip());
        } catch (SmsRateLimitException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 429);
        } catch (SmsSendFailedException) {
            return response()->json([
                'message' => '验证码发送失败，请稍后重试',
            ], 503);
        }

        $payload = [
            'message' => '验证码已发送',
        ];
        if (config('sms.mp_login.expose_debug_code')) {
            $payload['debug_code'] = $code;
        }

        return response()->json($payload);
    }

    public function verifySms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'regex:/^1\d{10}$/'],
            'code' => ['required', 'digits:6'],
            'login_code' => ['nullable', 'string', 'max:128'],
        ]);

        $phone = $this->mpAuthService->normalizeLoginPhone((string) $validated['phone']);
        $code = (string) $validated['code'];

        $verified = $this->mpAuthService->verifySmsCode($phone, $code);
        if (! $verified) {
            return response()->json([
                'message' => '验证码错误或已过期',
            ], 422);
        }

        $openid = $this->resolveOpenidFromLoginCode($validated['login_code'] ?? null);

        return $this->loginSuccessResponse($phone, $openid);
    }

    public function loginWithWechatPhone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login_code' => ['required', 'string', 'max:128'],
            'phone_code' => ['required', 'string', 'max:512'],
        ]);

        if (! $this->wechatMiniService->isConfigured()) {
            return response()->json([
                'message' => '微信快捷登录未配置，请使用购票手机号验证码登录',
            ], 422);
        }

        try {
            $session = $this->wechatMiniService->codeToSession((string) $validated['login_code']);
            $rawPhone = $this->wechatMiniService->getPhoneByCode((string) $validated['phone_code']);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $phone = $this->mpAuthService->normalizeLoginPhone($rawPhone);
        if (! $this->mpAuthService->canLoginForBooking($phone)) {
            return $this->loginDeniedResponse($phone);
        }

        return $this->loginSuccessResponse($phone, $session['openid']);
    }

    private function loginSuccessResponse(string $phone, ?string $openid = null): JsonResponse
    {
        $token = $this->mpAuthService->issueToken($phone, $openid);

        return response()->json([
            'message' => '登录成功',
            'token' => $token,
            'token_type' => 'Bearer',
            'phone' => $phone,
            'has_openid' => $openid !== null && $openid !== '',
        ]);
    }

    private function loginDeniedResponse(string $phone): JsonResponse
    {
        $hasPresaleParent = Order::query()
            ->where('contact_phone', $phone)
            ->whereNull('parent_order_id')
            ->whereHas('product', function ($query): void {
                $query->where('fulfillment_mode', FulfillmentMode::Deferred->value);
            })
            ->exists();

        if ($hasPresaleParent) {
            return response()->json([
                'message' => '该购票订单下未找到权益记录，请联系客服',
            ], 422);
        }

        $hasAnyOrder = Order::query()->where('contact_phone', $phone)->exists();

        return response()->json([
            'message' => $hasAnyOrder
                ? '该手机号订单不是预售类型，无法使用预约小程序'
                : '当前手机号与 OTA 购票预留手机号不一致，请使用下单时的联系人手机号',
        ], 422);
    }

    private function resolveOpenidFromLoginCode(?string $loginCode): ?string
    {
        if ($loginCode === null || $loginCode === '' || ! $this->wechatMiniService->isConfigured()) {
            return null;
        }

        try {
            $session = $this->wechatMiniService->codeToSession($loginCode);

            return $session['openid'];
        } catch (\Throwable) {
            return null;
        }
    }
}
