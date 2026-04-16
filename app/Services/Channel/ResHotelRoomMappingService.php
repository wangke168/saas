<?php

namespace App\Services\Channel;

use App\Models\Res\ResRoomType;

class ResHotelRoomMappingService
{
    /**
     * @return array{hotel_id:int,room_type_id:int}|null
     */
    public function resolve(
        string $externalHotelCode,
        string $externalRoomTypeCode,
        ?string $poiId,
        ?string $softwareProviderApiType
    ): ?array {
        $query = ResRoomType::query()
            ->select(['res_room_types.id', 'res_room_types.hotel_id'])
            ->join('res_hotels', 'res_hotels.id', '=', 'res_room_types.hotel_id')
            ->where('res_hotels.external_hotel_id', $externalHotelCode)
            ->where('res_room_types.external_room_id', $externalRoomTypeCode);

        if ($softwareProviderApiType !== null && $softwareProviderApiType !== '') {
            $query->join('software_providers', 'software_providers.id', '=', 'res_hotels.software_provider_id')
                ->where('software_providers.api_type', $softwareProviderApiType);
        }

        if ($poiId !== null && $poiId !== '') {
            $query->where(function ($nested) use ($poiId): void {
                $nested->where('res_hotels.code', $poiId)
                    ->orWhere('res_hotels.external_hotel_id', $poiId);
            });
        }

        $roomType = $query->first();
        if ($roomType === null) {
            return null;
        }

        return [
            'hotel_id' => (int) $roomType->hotel_id,
            'room_type_id' => (int) $roomType->id,
        ];
    }
}
