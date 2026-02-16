<?php

namespace App\Enums;

enum PriceRuleType: string
{
    case WEEKDAY = 'weekday'; // 周几规则（旧格式，兼容）
    case DATE_RANGE = 'date_range'; // 日期区间规则（旧格式，兼容）
    case COMBINED = 'combined'; // 统一规则（新格式：日期范围 + 周几可选）

    public function label(): string
    {
        return match($this) {
            self::WEEKDAY => '周几规则',
            self::DATE_RANGE => '日期区间规则',
            self::COMBINED => '统一规则',
        };
    }
}

