<?php

namespace App\Support;

use App\Enums\GuestIdType;
use App\Models\OrderBooking;

/**
 * 将小程序预约单入住人转为资源方 guest_info 行结构（勿写入 orders 表，避免覆盖 OTA 购票人信息）。
 */
final class MpBookingGuestInfo
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function toOrderGuestInfo(OrderBooking $booking): array
    {
        $name = trim((string) ($booking->guest_name ?? ''));
        $idCode = trim((string) ($booking->guest_id_card ?? ''));
        if ($name === '' && $idCode === '') {
            return [];
        }

        $idType = $booking->guest_id_type instanceof GuestIdType
            ? $booking->guest_id_type
            : GuestIdType::tryFrom((string) ($booking->guest_id_type ?? '')) ?? GuestIdType::IdCard;

        $credentialType = $idType === GuestIdType::Passport ? 1 : 0;

        return [
            [
                'name' => $name,
                'idCode' => $idCode,
                'cardNo' => $idCode,
                'credentialType' => $credentialType,
                'credentialNo' => $idCode,
                'guest_id_type' => $idType->value,
                'mobile' => trim((string) ($booking->guest_phone ?? '')),
            ],
        ];
    }
}
