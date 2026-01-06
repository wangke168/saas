<?php

namespace App\Enums;

enum PkgOrderItemStatus: string
{
    case PENDING = 'pending'; // 待处理
    case PROCESSING = 'processing'; // 处理中
    case SUCCESS = 'success'; // 成功
    case FAILED = 'failed'; // 失败

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
