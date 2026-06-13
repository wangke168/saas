<?php

namespace App\Services\Sms;

use App\Exceptions\Sms\SmsSendFailedException;
use Illuminate\Support\Facades\Log;
use Overtrue\EasySms\EasySms;
use Overtrue\EasySms\Exceptions\NoGatewayAvailableException;
use Throwable;

/**
 * 阿里云短信发送（基于 overtrue/easy-sms）。
 */
final class AliyunSmsService
{
    public function driver(): string
    {
        return (string) config('sms.driver', 'log');
    }

    public function isConfigured(): bool
    {
        if ($this->driver() === 'log') {
            return true;
        }

        $aliyun = config('sms.aliyun', []);

        return ($aliyun['access_key_id'] ?? '') !== ''
            && ($aliyun['access_key_secret'] ?? '') !== ''
            && ($aliyun['sign_name'] ?? '') !== ''
            && ($aliyun['template_code'] ?? '') !== '';
    }

    public function sendVerificationCode(string $phone, string $code): void
    {
        if ($this->driver() === 'log') {
            Log::info('SMS verification code (log driver)', [
                'phone' => $phone,
                'code' => $code,
            ]);

            return;
        }

        if (! $this->isConfigured()) {
            throw new SmsSendFailedException('短信服务未配置');
        }

        $templateCode = (string) config('sms.aliyun.template_code', '');

        try {
            $result = $this->client()->send($phone, [
                'template' => $templateCode,
                'data' => [
                    'code' => $code,
                ],
            ]);

            Log::info('阿里云短信发送成功', [
                'phone' => $phone,
                'template' => $templateCode,
                'result' => $result,
            ]);
        } catch (NoGatewayAvailableException $e) {
            $errors = [];
            foreach ($e->getExceptions() as $gateway => $exception) {
                $errors[$gateway] = $exception->getMessage();
            }

            Log::warning('阿里云短信发送失败', [
                'phone' => $phone,
                'template' => $templateCode,
                'errors' => $errors,
            ]);

            throw new SmsSendFailedException('短信发送失败', 0, $e);
        } catch (Throwable $e) {
            Log::warning('阿里云短信发送异常', [
                'phone' => $phone,
                'message' => $e->getMessage(),
            ]);

            throw new SmsSendFailedException('短信发送失败', 0, $e);
        }
    }

    private function client(): EasySms
    {
        $aliyun = config('sms.aliyun', []);

        return new EasySms([
            'timeout' => (float) config('sms.timeout', 5.0),
            'default' => [
                'gateways' => ['aliyun'],
            ],
            'gateways' => [
                'aliyun' => [
                    'access_key_id' => (string) ($aliyun['access_key_id'] ?? ''),
                    'access_key_secret' => (string) ($aliyun['access_key_secret'] ?? ''),
                    'sign_name' => (string) ($aliyun['sign_name'] ?? ''),
                ],
            ],
        ]);
    }
}
