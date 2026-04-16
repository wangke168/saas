<?php

namespace App\Services\Channel;

use App\Jobs\PushChangedInventoryToOtaJob;
use Illuminate\Support\Facades\Log;

class ResHotelRoomStockOtaPushService
{
    public function dispatch(int $hotelId, int $roomTypeId, string $date): int
    {
        PushChangedInventoryToOtaJob::dispatch($roomTypeId, [$date], null, true)
            ->onQueue('ota-push');

        Log::info('开关房同步后触发主库存OTA推送任务', [
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'date' => $date,
        ]);

        return 1;
    }
}
