<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyChannelSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = (string) $request->route('provider', '');
        $config = config("channel_sync.providers.{$provider}");

        if (!is_array($config)) {
            return response()->json([
                'code' => 1005,
                'message' => 'source_not_allowed',
            ], 403);
        }

        $timestamp = (string) $request->header('X-Timestamp', '');
        $signature = strtolower((string) $request->header('X-Signature', ''));
        $requestId = (string) $request->header('X-Request-Id', '');

        if ($timestamp === '' || $signature === '') {
            return response()->json([
                'code' => 1002,
                'message' => 'invalid_signature',
            ], 401);
        }

        $ttl = (int) ($config['timestamp_ttl'] ?? 300);
        $timeDiff = $this->timeDiff($timestamp);

        if (!$this->isTimestampValid($timestamp, $ttl)) {
            $this->logDebugSignature(
                $provider,
                $requestId,
                $timestamp,
                $timeDiff,
                '',
                $signature,
                '',
                'timestamp_expired'
            );

            return response()->json([
                'code' => 1004,
                'message' => 'timestamp_expired',
            ], 401);
        }

        $rawBody = (string) $request->getContent();
        $expected = $this->makeSignature($timestamp, $rawBody, (string) ($config['secret'] ?? ''));
        $bodyHash = hash('sha256', $rawBody);

        if ($expected === '' || !hash_equals($expected, $signature)) {
            Log::warning('渠道签名校验失败', [
                'provider' => $provider,
                'request_id' => $requestId,
            ]);
            $this->logDebugSignature(
                $provider,
                $requestId,
                $timestamp,
                $timeDiff,
                $expected,
                $signature,
                $bodyHash,
                'invalid_signature'
            );

            return response()->json([
                'code' => 1002,
                'message' => 'invalid_signature',
            ], 401);
        }

        $this->logDebugSignature(
            $provider,
            $requestId,
            $timestamp,
            $timeDiff,
            $expected,
            $signature,
            $bodyHash,
            'signature_ok'
        );

        return $next($request);
    }

    private function isTimestampValid(string $timestamp, int $ttl): bool
    {
        if (!ctype_digit($timestamp)) {
            return false;
        }

        $timestampInt = (int) $timestamp;
        return abs(time() - $timestampInt) <= $ttl;
    }

    private function makeSignature(string $timestamp, string $rawBody, string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        $payload = $timestamp . '.' . $rawBody;
        return hash_hmac('sha256', $payload, $secret);
    }

    private function timeDiff(string $timestamp): ?int
    {
        if (!ctype_digit($timestamp)) {
            return null;
        }

        return time() - (int) $timestamp;
    }

    private function logDebugSignature(
        string $provider,
        string $requestId,
        string $timestamp,
        ?int $timeDiff,
        string $expected,
        string $received,
        string $bodyHash,
        string $stage
    ): void {
        if (!config('channel_sync.debug_signature', false)) {
            return;
        }

        Log::info('渠道签名调试日志', [
            'provider' => $provider,
            'request_id' => $requestId,
            'timestamp' => $timestamp,
            'time_diff_seconds' => $timeDiff,
            'body_sha256' => $bodyHash,
            'expected_signature_prefix' => substr($expected, 0, 12),
            'received_signature_prefix' => substr($received, 0, 12),
            'stage' => $stage,
        ]);
    }
}
