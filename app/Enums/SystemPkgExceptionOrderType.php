<?php

namespace App\Enums;

enum SystemPkgExceptionOrderType: string
{
    case SPLIT_ORDER_FAILED = 'SPLIT_ORDER_FAILED'; // 拆单处理失败
    case TICKET_ORDER_FAILED = 'TICKET_ORDER_FAILED'; // 门票订单失败
    case HOTEL_ORDER_FAILED = 'HOTEL_ORDER_FAILED'; // 酒店订单失败
    case INVENTORY_INSUFFICIENT = 'INVENTORY_INSUFFICIENT'; // 库存不足
    case PRICE_MISMATCH = 'PRICE_MISMATCH'; // 价格不匹配
    case API_ERROR = 'API_ERROR'; // API接口错误
    case TIMEOUT = 'TIMEOUT'; // 超时

    public function label(): string
    {
        return match($this) {
            self::SPLIT_ORDER_FAILED => '拆单处理失败',
            self::TICKET_ORDER_FAILED => '门票订单失败',
            self::HOTEL_ORDER_FAILED => '酒店订单失败',
            self::INVENTORY_INSUFFICIENT => '库存不足',
            self::PRICE_MISMATCH => '价格不匹配',
            self::API_ERROR => 'API接口错误',
            self::TIMEOUT => '超时',
        };
    }
}

