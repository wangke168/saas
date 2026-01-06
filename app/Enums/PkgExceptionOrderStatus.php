<?php

namespace App\Enums;

enum PkgExceptionOrderStatus: string
{
    case PENDING = 'pending'; // 待处理
    case PROCESSING = 'processing'; // 处理中
    case RESOLVED = 'resolved'; // 已解决

    public function label(): string
    {
        return match($this) {
            self::PENDING => '待处理',
            self::PROCESSING => '处理中',
            self::RESOLVED => '已解决',
        };
    }
}
