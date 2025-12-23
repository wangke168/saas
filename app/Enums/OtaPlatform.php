<?php

namespace App\Enums;

enum OtaPlatform: string
{
    case CTRIP = 'ctrip'; // 携程
    case FLIGGY = 'fliggy'; // 飞猪
    case MEITUAN = 'meituan'; // 美团

    public function label(): string
    {
        return match($this) {
            self::CTRIP => '携程',
            self::FLIGGY => '飞猪',
            self::MEITUAN => '美团',
        };
    }
}

