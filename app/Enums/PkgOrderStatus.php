<?php

namespace App\Enums;

enum PkgOrderStatus: string
{
    case PAID = 'paid'; // 已支付
    case CONFIRMED = 'confirmed'; // 已确认
    case FAILED = 'failed'; // 失败
    case CANCELLED = 'cancelled'; // 已取消

    public function label(): string
    {
        return match($this) {
            self::PAID => '已支付',
            self::CONFIRMED => '已确认',
            self::FAILED => '失败',
            self::CANCELLED => '已取消',
        };
    }
}
