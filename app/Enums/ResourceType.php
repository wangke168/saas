<?php

namespace App\Enums;

enum ResourceType: string
{
    case TICKET = 'TICKET'; // 门票
    case HOTEL = 'HOTEL'; // 酒店

    public function label(): string
    {
        return match($this) {
            self::TICKET => '门票',
            self::HOTEL => '酒店',
        };
    }
}


