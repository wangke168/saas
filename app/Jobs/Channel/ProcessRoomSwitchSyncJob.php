<?php

namespace App\Jobs\Channel;

use App\Enums\PriceSource;
use App\Models\ChannelSyncRequest;
use App\Models\Inventory;
use App\Services\Channel\ResHotelRoomMappingService;
use App\Services\Channel\ResHotelRoomStockOtaPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessRoomSwitchSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public int $syncRequestId,
        public string $provider,
        public array $payload
    ) {
        $this->onQueue('resource-push');
    }

    public function handle(
        ResHotelRoomMappingService $mappingService,
        ResHotelRoomStockOtaPushService $otaPushService
    ): void {
        $syncRequest = ChannelSyncRequest::find($this->syncRequestId);
        if ($syncRequest === null) {
            return;
        }

        $syncRequest->update(['status' => 'processing']);

        $summary = [
            'accepted_count' => 0,
            'failed_count' => 0,
            'pushed_count' => 0,
            'failed_items' => [],
        ];

        $autoPush = (bool) config("channel_sync.providers.{$this->provider}.auto_push_ota", true);
        foreach ((array) ($this->payload['data'] ?? []) as $hotelItem) {
            $externalHotelCode = (string) ($hotelItem['hotel_name'] ?? '');
            $poiId = isset($hotelItem['poi_id']) ? (string) $hotelItem['poi_id'] : null;

            foreach ((array) ($hotelItem['room_types'] ?? []) as $roomTypeItem) {
                $externalRoomTypeCode = (string) ($roomTypeItem['room_type_name'] ?? '');
                $availability = (array) ($roomTypeItem['availability'] ?? []);

                $mapping = $mappingService->resolve(
                    $externalHotelCode,
                    $externalRoomTypeCode,
                    $poiId,
                    null
                );

                if ($mapping === null) {
                    $summary['failed_count']++;
                    $summary['failed_items'][] = [
                        'hotel_code' => $externalHotelCode,
                        'room_type_code' => $externalRoomTypeCode,
                        'reason' => 'mapping_not_found',
                    ];
                    continue;
                }

                foreach ($availability as $dailyItem) {
                    $bizDate = (string) ($dailyItem['date'] ?? '');
                    if ($bizDate === '') {
                        continue;
                    }

                    $dailyStatus = (int) ($dailyItem['status'] ?? 0);
                    $targetOpen = $dailyStatus === 1;

                    $changed = $this->applyRoomSwitch(
                        $mapping['room_type_id'],
                        $bizDate,
                        $targetOpen
                    );

                    if ($changed && $autoPush) {
                        $summary['pushed_count'] += $otaPushService->dispatch(
                            $mapping['hotel_id'],
                            $mapping['room_type_id'],
                            $bizDate
                        );
                    }
                }

                $summary['accepted_count']++;
            }
        }

        $syncRequest->update([
            'status' => 'processed',
            'result_summary' => $summary,
            'processed_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $syncRequest = ChannelSyncRequest::find($this->syncRequestId);
        if ($syncRequest !== null) {
            $syncRequest->update([
                'status' => 'failed',
                'processed_at' => now(),
                'result_summary' => [
                    'message' => $exception->getMessage(),
                ],
            ]);
        }

        Log::error('处理开关房同步任务失败', [
            'sync_request_id' => $this->syncRequestId,
            'provider' => $this->provider,
            'error' => $exception->getMessage(),
        ]);
    }

    private function applyRoomSwitch(int $roomTypeId, string $bizDate, bool $open): bool
    {
        return (bool) DB::transaction(function () use ($roomTypeId, $bizDate, $open): bool {
            $inventory = Inventory::lockForUpdate()
                ->where('room_type_id', $roomTypeId)
                ->whereDate('date', $bizDate)
                ->first();

            if ($inventory === null) {
                Inventory::create([
                    'room_type_id' => $roomTypeId,
                    'date' => $bizDate,
                    'total_quantity' => 0,
                    'available_quantity' => 0,
                    'locked_quantity' => 0,
                    'source' => PriceSource::API->value,
                    'is_closed' => !$open,
                ]);

                return true;
            }

            $newClosed = !$open;
            if ((bool) $inventory->is_closed === $newClosed) {
                return false;
            }

            $inventory->update([
                'is_closed' => $newClosed,
                'source' => PriceSource::API->value,
            ]);

            return true;
        });
    }
}
