<?php

namespace App\Enums;

enum PkgExceptionOrderType: string
{
    case SPLIT_ORDER_FAILED = 'split_order_failed'; // 拆单失败
    case TICKET_ORDER_FAILED = 'ticket_order_failed'; // 门票下单失败
    case HOTEL_ORDER_FAILED = 'hotel_order_failed'; // 酒店下单失败
    case PRICE_MISMATCH = 'price_mismatch'; // 价格不匹配
    case INVENTORY_INSUFFICIENT = 'inventory_insufficient'; // 库存不足

    public function label(): string
    {
        return match($this) {
            self::SPLIT_ORDER_FAILED => '拆单失败',
            self::TICKET_ORDER_FAILED => '门票下单失败',
            self::HOTEL_ORDER_FAILED => '酒店下单失败',
            self::PRICE_MISMATCH => '价格不匹配',
            self::INVENTORY_INSUFFICIENT => '库存不足',
        };
    }
}
