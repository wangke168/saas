<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * 产品「不可订时段」按「房晚日期」与库存表 date 一致（闭区间 start_date～end_date）。
 * 入住校验：从入住日起连续 stay_days 个房晚任一晚落在不可订区间内则不可售。
 */
final class ProductUnavailableNightService
{
    public static function ensurePeriodsLoaded(Product $product): void
    {
        if (! $product->relationLoaded('unavailablePeriods')) {
            $product->load('unavailablePeriods');
        }
    }

    public static function isNightUnavailable(Product $product, string $dateYmd): bool
    {
        self::ensurePeriodsLoaded($product);
        $night = Carbon::parse($dateYmd)->startOfDay();

        foreach ($product->unavailablePeriods as $period) {
            $start = self::toCarbonStart($period->start_date);
            $end = self::toCarbonStart($period->end_date);
            if ($night->gte($start) && $night->lte($end)) {
                return true;
            }
        }

        return false;
    }

    public static function checkInTouchesUnavailable(Product $product, string $checkInYmd): bool
    {
        $stay = max(1, (int) ($product->stay_days ?? 1));
        $d = Carbon::parse($checkInYmd)->startOfDay();

        for ($i = 0; $i < $stay; $i++) {
            if (self::isNightUnavailable($product, $d->copy()->addDays($i)->toDateString())) {
                return true;
            }
        }

        return false;
    }

    private static function toCarbonStart(CarbonInterface|string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }
}
