<?php

namespace App\Services\Channel;

use App\Models\RoomType;

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
        unset($poiId, $softwareProviderApiType);

        $query = RoomType::query()
            ->select(['room_types.id', 'room_types.hotel_id'])
            ->join('hotels', 'hotels.id', '=', 'room_types.hotel_id')
            // 映射必须同时命中酒店与房型，避免房型编码跨酒店重名误匹配
            ->where('hotels.external_code', $externalHotelCode)
            ->where('room_types.external_code', $externalRoomTypeCode);

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
