<?php

namespace App\Enums;

enum PriceRuleType: string
{
    case WEEKDAY = 'weekday'; // 周几规则
    case DATE_RANGE = 'date_range'; // 日期区间规则

    public function label(): string
    {
        return match($this) {
            self::WEEKDAY => '周几规则',
            self::DATE_RANGE => '日期区间规则',
        };
    }
}

