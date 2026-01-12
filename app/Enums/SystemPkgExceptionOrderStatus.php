<?php

namespace App\Enums;

enum SystemPkgExceptionOrderStatus: string
{
    case PENDING = 'PENDING'; // 待处理
    case PROCESSING = 'PROCESSING'; // 处理中
    case RESOLVED = 'RESOLVED'; // 已解决

    public function label(): string
    {
        return match($this) {
            self::PENDING => '待处理',
            self::PROCESSING => '处理中',
            self::RESOLVED => '已解决',
        };
    }
}


