<?php

namespace App\Enums;

enum ExceptionOrderType: string
{
    case API_ERROR = 'api_error'; // 接口报错
    case TIMEOUT = 'timeout'; // 超时
    case INVENTORY_MISMATCH = 'inventory_mismatch'; // 库存不匹配
    case PRICE_MISMATCH = 'price_mismatch'; // 价格不匹配

    public function label(): string
    {
        return match($this) {
            self::API_ERROR => '接口报错',
            self::TIMEOUT => '超时',
            self::INVENTORY_MISMATCH => '库存不匹配',
            self::PRICE_MISMATCH => '价格不匹配',
        };
    }
}

