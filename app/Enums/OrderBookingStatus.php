<?php

namespace App\Enums;

enum OrderBookingStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Fulfilling = 'fulfilling';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => '待支付',
            self::Paid => '已支付',
            self::Fulfilling => '确认中',
            self::Confirmed => '预约成功',
            self::Failed => '失败',
            self::Cancelled => '已取消',
        };
    }
}
