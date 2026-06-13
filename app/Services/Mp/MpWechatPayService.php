<?php

namespace App\Services\Mp;

use App\Enums\OrderBookingStatus;
use App\Models\OrderBooking;
use Illuminate\Support\Facades\Log;
use Yansongda\Artful\Exception\InvalidResponseException;
use Yansongda\LaravelPay\Facades\Pay;

/**
 * 小程序预约补差：微信 JSAPI（mini）下单与回调验签落账。
 */
final class MpWechatPayService
{
    public function __construct(
        private readonly MpBookingFulfillmentService $bookingFulfillmentService,
    ) {}

    public function isConfigured(): bool
    {
        $privateKeyPath = (string) config('wechat.pay.private_key_path', '');
        $mchCertPath = (string) config('wechat.pay.mch_cert_path', '');

        $publicKeyPath = (string) config('wechat.pay.public_key_path', '');
        $publicKeyId = (string) config('wechat.pay.public_key_id', '');

        return config('wechat.pay.mch_id') !== ''
            && config('wechat.pay.api_v3_key') !== ''
            && config('wechat.pay.notify_url') !== ''
            && config('wechat.mini_program.app_id') !== ''
            && $privateKeyPath !== ''
            && is_file($privateKeyPath)
            && $mchCertPath !== ''
            && is_file($mchCertPath)
            && $publicKeyId !== ''
            && $publicKeyPath !== ''
            && is_file($publicKeyPath);
    }

    /**
     * @return array{
     *     provider: string,
     *     timeStamp: string,
     *     nonceStr: string,
     *     package: string,
     *     signType: string,
     *     paySign: string,
     *     out_trade_no: string,
     * }
     */
    public function createMiniPayment(OrderBooking $booking, string $openid): array
    {
        $this->assertConfigured();

        $amountFen = $this->amountToFen((float) $booking->surcharge_amount);
        if ($amountFen <= 0) {
            throw new \InvalidArgumentException('当前预约无需支付补差');
        }

        if ($openid === '') {
            throw new \InvalidArgumentException('缺少微信 openid，请使用微信手机号快捷登录后支付');
        }

        $payload = [
            'out_trade_no' => (string) $booking->booking_no,
            'description' => '预售预约补差',
            'amount' => [
                'total' => $amountFen,
                'currency' => 'CNY',
            ],
            'payer' => [
                'openid' => $openid,
            ],
        ];

        if ($booking->payment_expires_at !== null) {
            $payload['time_expire'] = $booking->payment_expires_at
                ->timezone('Asia/Shanghai')
                ->format('Y-m-d\TH:i:sP');
        }

        Log::info('MpWechatPayService: 发起小程序支付下单', [
            'booking_id' => $booking->id,
            'booking_no' => $booking->booking_no,
            'amount_fen' => $amountFen,
            'mini_app_id' => config('wechat.mini_program.app_id'),
            'openid_prefix' => substr($openid, 0, 6),
        ]);

        try {
            $result = Pay::wechat()->mini($payload);
        } catch (InvalidResponseException $exception) {
            throw new \RuntimeException($this->formatWechatPayError($exception), 0, $exception);
        }

        return [
            'provider' => 'wechat',
            'timeStamp' => (string) ($result->get('timeStamp') ?? $result->get('timestamp') ?? ''),
            'nonceStr' => (string) ($result->get('nonceStr') ?? $result->get('nonce_str') ?? ''),
            'package' => (string) ($result->get('package') ?? ''),
            'signType' => (string) ($result->get('signType') ?? $result->get('sign_type') ?? 'RSA'),
            'paySign' => (string) ($result->get('paySign') ?? $result->get('pay_sign') ?? ''),
            'out_trade_no' => (string) $booking->booking_no,
        ];
    }

    /**
     * 验签并解析微信异步通知，支付成功时触发履约。
     */
    public function handlePaidNotify(): void
    {
        $this->assertConfigured();

        $callbackData = Pay::wechat()->callback()->all();

        $transaction = $this->extractTransactionPayload($callbackData);

        Log::info('MpWechatPayService: 收到微信支付回调', [
            'event_type' => data_get($callbackData, 'event_type'),
            'out_trade_no' => data_get($transaction, 'out_trade_no'),
            'transaction_id' => data_get($transaction, 'transaction_id'),
            'trade_state' => data_get($transaction, 'trade_state'),
        ]);

        $eventType = (string) (data_get($callbackData, 'event_type') ?? '');
        if ($eventType !== '' && $eventType !== 'TRANSACTION.SUCCESS') {
            Log::info('MpWechatPayService: 忽略非支付成功事件', ['event_type' => $eventType]);

            return;
        }

        $tradeState = (string) (data_get($transaction, 'trade_state') ?? '');
        if ($tradeState !== '' && $tradeState !== 'SUCCESS') {
            return;
        }

        $outTradeNo = (string) (data_get($transaction, 'out_trade_no') ?? '');
        $transactionId = (string) (data_get($transaction, 'transaction_id') ?? '');
        if ($outTradeNo === '' || $transactionId === '') {
            throw new \RuntimeException('微信支付回调缺少订单号');
        }

        $booking = OrderBooking::query()
            ->where('booking_no', $outTradeNo)
            ->first();

        if ($booking === null) {
            throw new \RuntimeException('预约单不存在：'.$outTradeNo);
        }

        $paidFen = (int) data_get($transaction, 'amount.total', 0);
        $expectedFen = $this->amountToFen((float) $booking->surcharge_amount);
        if ($paidFen !== $expectedFen) {
            throw new \RuntimeException(sprintf(
                '支付金额不匹配 booking=%s expected=%d paid=%d',
                $outTradeNo,
                $expectedFen,
                $paidFen,
            ));
        }

        $this->fulfillPaidBooking($booking, $transactionId);
    }

    public function fulfillPaidBooking(OrderBooking $booking, string $transactionId): void
    {
        $booking->refresh();

        $status = $booking->status instanceof OrderBookingStatus
            ? $booking->status
            : OrderBookingStatus::from((string) $booking->status);

        if ($status === OrderBookingStatus::Confirmed) {
            Log::info('MpWechatPayService: 预约已履约，幂等跳过', [
                'booking_id' => $booking->id,
                'transaction_id' => $transactionId,
            ]);

            return;
        }

        if ($status === OrderBookingStatus::Paid && $booking->payment_no === $transactionId) {
            Log::info('MpWechatPayService: 支付已落账，幂等跳过', [
                'booking_id' => $booking->id,
                'transaction_id' => $transactionId,
            ]);

            return;
        }

        $this->bookingFulfillmentService->fulfill(
            $booking,
            $transactionId,
            '小程序预约补差支付成功',
            'fulfilled_via_wechat_pay',
        );
    }

    public function amountToFen(float $amountYuan): int
    {
        return (int) round($amountYuan * 100);
    }

    /**
     * 微信 V3 回调验签解密后，交易字段在 resource.ciphertext 内，而非顶层。
     *
     * @return array<string, mixed>
     */
    protected function extractTransactionPayload(array $callback): array
    {
        $ciphertext = data_get($callback, 'resource.ciphertext');
        if (is_array($ciphertext) && $ciphertext !== []) {
            return $ciphertext;
        }

        $resource = data_get($callback, 'resource');
        if (is_array($resource) && isset($resource['out_trade_no'])) {
            return $resource;
        }

        if (isset($callback['out_trade_no'])) {
            return $callback;
        }

        return [];
    }

    protected function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('微信支付未正确配置，请检查商户号、证书与 .env');
        }
    }

    protected function formatWechatPayError(InvalidResponseException $exception): string
    {
        $response = $exception->response;
        if (is_array($response)) {
            $code = (string) ($response['code'] ?? '');
            $message = (string) ($response['message'] ?? $exception->getMessage());
        } elseif (is_object($response) && method_exists($response, 'get')) {
            $code = (string) ($response->get('code') ?? '');
            $message = (string) ($response->get('message') ?? $exception->getMessage());
        } else {
            return $exception->getMessage();
        }

        if ($code === 'PARAM_ERROR' && str_contains($message, 'openid')) {
            return '微信 openid 无效：请确认小程序 AppID 与后端 WECHAT_MINI_APP_ID 一致，并使用微信快捷登录后支付';
        }

        if ($code === 'RESOURCE_NOT_EXISTS' && str_contains($message, '公钥')) {
            return '请在 .env 配置 WECHAT_PAY_PUBLIC_KEY_ID 与 WECHAT_PAY_PUBLIC_KEY_PATH（商户平台 API 安全下载的微信支付公钥）';
        }

        return $message !== '' ? "微信支付失败：{$message}" : $exception->getMessage();
    }
}
