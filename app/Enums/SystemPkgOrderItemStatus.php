<?php

namespace App\Enums;

enum SystemPkgOrderItemStatus: string
{
    case PENDING = 'PENDING'; // 待处理
    case PROCESSING = 'PROCESSING'; // 处理中
    case SUCCESS = 'SUCCESS'; // 成功
    case FAILED = 'FAILED'; // 失败

    public function label(): string
    {
        return match($this) {
            self::PENDING => '待处理',
            self::PROCESSING => '处理中',
            self::SUCCESS => '成功',
            self::FAILED => '失败',
        };
    }
}


