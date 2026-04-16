<?php

namespace App\Http\Controllers\Channel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Channel\RoomSwitchSyncRequest;
use App\Jobs\Channel\ProcessRoomSwitchSyncJob;
use App\Models\ChannelSyncRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RoomSwitchSyncController extends Controller
{
    public function store(RoomSwitchSyncRequest $request, string $provider): JsonResponse
    {
        $payload = $request->validated();
        $requestId = (string) $payload['request_id'];
        $source = (string) $payload['source'];
        $rawBody = (string) $request->getContent();
        $rawBodySize = strlen($rawBody);
        $rawBodySha256 = hash('sha256', $rawBody);
        $payloadHash = hash('sha256', $rawBody);

        $syncRequest = ChannelSyncRequest::firstOrCreate(
            [
                'provider' => $provider,
                'request_id' => $requestId,
            ],
            [
                'source' => $source,
                'payload_hash' => $payloadHash,
                'status' => 'received',
            ]
        );

        if (!$syncRequest->wasRecentlyCreated) {
            if ($syncRequest->payload_hash !== $payloadHash) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'idempotency_conflict',
                ], 409);
            }

            return response()->json([
                'code' => 0,
                'message' => 'accepted',
                'data' => [
                    'request_id' => $requestId,
                    'idempotent_hit' => true,
                ],
            ]);
        }

        if ((bool) config('channel_sync.debug_payload', false)) {
            $limitBytes = (int) config('channel_sync.payload_log_limit_bytes', 50_000);
            $truncated = $rawBodySize > $limitBytes
                ? mb_strcut($rawBody, 0, $limitBytes, 'UTF-8')
                : $rawBody;

            Log::info('开关房同步调试：记录原始 JSON payload', [
                'provider' => $provider,
                'request_id' => $requestId,
                'source' => $source,
                'raw_body_size' => $rawBodySize,
                'raw_body_sha256' => $rawBodySha256,
                'raw_body_truncated' => $truncated,
                'truncated' => $rawBodySize > $limitBytes,
            ]);
        }

        ProcessRoomSwitchSyncJob::dispatch(
            $syncRequest->id,
            $provider,
            $payload
        )->onQueue('resource-push');

        Log::info('接收开关房同步请求成功', [
            'provider' => $provider,
            'request_id' => $requestId,
            'items_count' => count((array) ($payload['data'] ?? [])),
        ]);

        return response()->json([
            'code' => 0,
            'message' => 'accepted',
            'data' => [
                'request_id' => $requestId,
                'idempotent_hit' => false,
            ],
        ]);
    }
}
