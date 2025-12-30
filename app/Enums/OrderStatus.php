<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PAID_PENDING = 'paid_pending'; // 待确认（预下单场景：未支付仅占库存；其他场景：已支付待确认）
    case CONFIRMING = 'confirming'; // 确认中
    case CONFIRMED = 'confirmed'; // 预订成功
    case REJECTED = 'rejected'; // 预订失败/拒单
    case CANCEL_REQUESTED = 'cancel_requested'; // 申请取消中
    case CANCEL_REJECTED = 'cancel_rejected'; // 取消拒绝
    case CANCEL_APPROVED = 'cancel_approved'; // 取消通过
    case VERIFIED = 'verified'; // 核销订单

    public function label(): string
    {
        return match($this) {
            self::PAID_PENDING => '待确认',
            self::CONFIRMING => '确认中',
            self::CONFIRMED => '预订成功',
            self::REJECTED => '预订失败/拒单',
            self::CANCEL_REQUESTED => '申请取消中',
            self::CANCEL_REJECTED => '取消拒绝',
            self::CANCEL_APPROVED => '取消通过',
            self::VERIFIED => '核销订单',
        };
    }
}

