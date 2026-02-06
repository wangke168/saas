<?php

namespace App\Enums;

enum SystemPkgOrderStatus: string
{
    case PAID_PENDING = 'PAID_PENDING'; // 已支付/待确认
    case CONFIRMING = 'CONFIRMING'; // 确认中
    case CONFIRMED = 'CONFIRMED'; // 预订成功
    case REJECTED = 'REJECTED'; // 预订失败/拒单
    case EXCEPTION = 'EXCEPTION'; // 异常订单
    case CANCEL_REQUESTED = 'CANCEL_REQUESTED'; // 申请取消中
    case CANCEL_REJECTED = 'CANCEL_REJECTED'; // 取消拒绝
    case CANCEL_APPROVED = 'CANCEL_APPROVED'; // 取消通过

    public function label(): string
    {
        return match($this) {
            self::PAID_PENDING => '已支付/待确认',
            self::CONFIRMING => '确认中',
            self::CONFIRMED => '预订成功',
            self::REJECTED => '预订失败/拒单',
            self::EXCEPTION => '异常订单',
            self::CANCEL_REQUESTED => '申请取消中',
            self::CANCEL_REJECTED => '取消拒绝',
            self::CANCEL_APPROVED => '取消通过',
        };
    }
}



