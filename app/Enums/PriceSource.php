<?php

namespace App\Enums;

enum PriceSource: string
{
    case MANUAL = 'manual'; // 人工维护
    case API = 'api'; // 接口推送

    public function label(): string
    {
        return match($this) {
            self::MANUAL => '人工维护',
            self::API => '接口推送',
        };
    }
}

