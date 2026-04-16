<?php

namespace App\Services\Channel;

use App\Jobs\Pkg\SyncProductPricesToOtaJob;
use App\Models\Pkg\PkgOtaProduct;
use App\Models\Pkg\PkgProduct;
use Illuminate\Support\Facades\Log;

class ResHotelRoomStockOtaPushService
{
    public function dispatch(int $hotelId, int $roomTypeId, string $date): int
    {
        $pkgProducts = PkgProduct::whereHas('hotelRoomTypes', function ($query) use ($hotelId, $roomTypeId): void {
            $query->where('hotel_id', $hotelId)
                ->where('room_type_id', $roomTypeId);
        })
            ->where('status', 1)
            ->get();

        if ($pkgProducts->isEmpty()) {
            return 0;
        }

        $pushedCount = 0;

        foreach ($pkgProducts as $pkgProduct) {
            $pkgOtaProducts = PkgOtaProduct::where('pkg_product_id', $pkgProduct->id)
                ->where('is_active', true)
                ->get();

            foreach ($pkgOtaProducts as $pkgOtaProduct) {
                $otaPlatform = $pkgOtaProduct->otaPlatform;
                if (!$otaPlatform) {
                    continue;
                }

                SyncProductPricesToOtaJob::dispatch(
                    $pkgProduct->id,
                    $otaPlatform->code->value,
                    [$date],
                    $pkgOtaProduct->id
                )->onQueue('ota-push');

                $pushedCount++;
            }
        }

        if ($pushedCount > 0) {
            Log::info('开关房同步后触发OTA推送任务', [
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'date' => $date,
                'push_count' => $pushedCount,
            ]);
        }

        return $pushedCount;
    }
}
