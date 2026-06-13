<?php

namespace App\Enums;

enum FulfillmentMode: string
{
    case Immediate = 'immediate';
    case Deferred = 'deferred';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => '落单即履约',
            self::Deferred => '小程序预约后履约',
        };
    }

    public function isDeferred(): bool
    {
        return $this === self::Deferred;
    }
}
