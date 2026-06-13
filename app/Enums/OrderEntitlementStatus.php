<?php

namespace App\Enums;

enum OrderEntitlementStatus: string
{
    case Pending = 'pending';
    case Booking = 'booking';
    case Booked = 'booked';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待预约',
            self::Booking => '预约中',
            self::Booked => '已预约',
            self::Cancelled => '已取消',
        };
    }
}
