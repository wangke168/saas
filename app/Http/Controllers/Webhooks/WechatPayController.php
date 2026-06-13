<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Mp\MpWechatPayService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Yansongda\LaravelPay\Facades\Pay;

/**
 * 微信支付异步通知（预约补差）。
 */
final class WechatPayController extends Controller
{
    public function __construct(
        private readonly MpWechatPayService $wechatPayService,
    ) {}

    public function notify(): Response
    {
        try {
            $this->wechatPayService->handlePaidNotify();

            return Pay::wechat()->success();
        } catch (\Throwable $exception) {
            Log::error('微信支付回调处理失败', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response('FAIL', 500);
        }
    }
}
