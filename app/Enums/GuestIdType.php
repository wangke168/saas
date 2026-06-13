<?php

namespace App\Enums;

enum GuestIdType: string
{
    case IdCard = 'id_card';
    case Passport = 'passport';

    public function label(): string
    {
        return match ($this) {
            self::IdCard => '身份证',
            self::Passport => '护照',
        };
    }

    public function numberLabel(): string
    {
        return match ($this) {
            self::IdCard => '身份证号码',
            self::Passport => '护照号码',
        };
    }
}
