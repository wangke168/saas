<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case OPERATOR = 'operator';

    public function label(): string
    {
        return match($this) {
            self::ADMIN => '超级管理员',
            self::OPERATOR => '运营',
        };
    }
}

