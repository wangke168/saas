<?php

namespace App\Jobs\Channel;

use App\Enums\PriceSource;
use App\Models\ChannelSyncRequest;
use App\Models\Res\ResHotelDailyStock;
use App\Services\Channel\ResHotelRoomMappingService;
use App\Services\Channel\ResHotelRoomStockOtaPushService;
use App\Services\Channel\RoomSwitchDecisionService;
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
        RoomSwitchDecisionService $decisionService,
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
        $providerApiType = config("channel_sync.providers.{$this->provider}.software_provider_api_type");

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
                    is_string($providerApiType) ? $providerApiType : null
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

                $targetOpen = $decisionService->shouldOpen($availability);

                foreach ($availability as $dailyItem) {
                    $bizDate = (string) ($dailyItem['date'] ?? '');
                    if ($bizDate === '') {
                        continue;
                    }

                    $changed = $this->applyRoomSwitch(
                        $mapping['hotel_id'],
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

    private function applyRoomSwitch(int $hotelId, int $roomTypeId, string $bizDate, bool $open): bool
    {
        return (bool) DB::transaction(function () use ($hotelId, $roomTypeId, $bizDate, $open): bool {
            $stock = ResHotelDailyStock::lockForUpdate()
                ->where('hotel_id', $hotelId)
                ->where('room_type_id', $roomTypeId)
                ->whereDate('biz_date', $bizDate)
                ->first();

            if ($stock === null) {
                $stock = ResHotelDailyStock::create([
                    'hotel_id' => $hotelId,
                    'room_type_id' => $roomTypeId,
                    'biz_date' => $bizDate,
                    'cost_price' => 0,
                    'sale_price' => 0,
                    'stock_total' => 0,
                    'stock_sold' => 0,
                    'stock_available' => 0,
                    'version' => 0,
                    'source' => PriceSource::API->value,
                    'is_closed' => !$open,
                ]);

                return true;
            }

            $newClosed = !$open;
            if ((bool) $stock->is_closed === $newClosed) {
                return false;
            }

            $stock->update([
                'is_closed' => $newClosed,
                'source' => PriceSource::API->value,
            ]);

            return true;
        });
    }
}
