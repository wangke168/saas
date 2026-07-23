<?php

namespace App\Enums;

/**
 * 横店酒店订单接口票性质（TicketProperty）。
 * 文档：Api_v2.0 — adult/child/elder/teacher/student/half。
 */
enum HengdianTicketProperty: string
{
    case Adult = 'adult';
    case Child = 'child';
    case Elder = 'elder';
    case Teacher = 'teacher';
    case Student = 'student';
    case Half = 'half';

    public function label(): string
    {
        return match ($this) {
            self::Adult => '成人票',
            self::Child => '儿童票/半票(child)',
            self::Elder => '老年票',
            self::Teacher => '教师票',
            self::Student => '学生票',
            self::Half => '半票(half)',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return self::tryFrom($normalized);
    }
}
