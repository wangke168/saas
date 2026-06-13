<?php

namespace App\Support;

use App\Models\Hotel;
use App\Models\RoomType;

final class HotelMediaPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function forHotel(Hotel $hotel): array
    {
        $imagePaths = is_array($hotel->images) ? $hotel->images : [];

        return [
            'cover_image' => $hotel->cover_image,
            'cover_image_url' => PublicMedia::url($hotel->cover_image),
            'images' => $imagePaths,
            'image_urls' => PublicMedia::urls($imagePaths),
            'introduction' => $hotel->introduction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forMpHotel(Hotel $hotel): array
    {
        $imagePaths = is_array($hotel->images) ? $hotel->images : [];

        return [
            'cover_image' => PublicMedia::url($hotel->cover_image),
            'images' => PublicMedia::galleryUrls($hotel->cover_image, $imagePaths),
            'introduction' => $hotel->introduction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forMpRoomType(RoomType $roomType): array
    {
        $imagePaths = is_array($roomType->images) ? $roomType->images : [];
        $facilities = array_values(array_filter([
            $roomType->bed_type ? '床型：'.$roomType->bed_type : null,
            $roomType->room_area ? '面积：'.$roomType->room_area.'㎡' : null,
            $roomType->breakfast ? '早餐：'.$roomType->breakfast : null,
            $roomType->max_occupancy ? '可住'.$roomType->max_occupancy.'人' : null,
        ]));

        return [
            'cover_image' => PublicMedia::url($roomType->cover_image),
            'images' => PublicMedia::galleryUrls($roomType->cover_image, $imagePaths),
            'bed_type' => $roomType->bed_type,
            'room_area' => $roomType->room_area !== null ? (float) $roomType->room_area : null,
            'breakfast' => $roomType->breakfast,
            'facilities' => $facilities,
        ];
    }
}
