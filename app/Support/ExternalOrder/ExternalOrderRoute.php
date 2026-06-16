<?php

namespace App\Support\ExternalOrder;

final class ExternalOrderRoute
{
    public const ROUTE_ID_MEITUAN = 'R246441';

    public const ROUTE_ID_CTRIP = 'R510735';

    public const ROUTE_CODE_MEITUAN = 'mtTicket';

    public const ROUTE_CODE_CTRIP = 'xcTicket';

    public const STATUS_PENDING = 10;

    public const STATUS_CONFIRMED = 20;

    public const STATUS_VERIFIED = 30;

    public const STATUS_CANCELLED = 50;

    public const API_SUCCESS_CODE = 'OK';

    /** @var list<string> */
    public const API_SUCCESS_CODES = ['OK', '200'];

    public const API_FAILURE_CODE = '500';

    public static function isSuccessResponseCode(string $code): bool
    {
        return in_array(strtoupper(trim($code)), self::API_SUCCESS_CODES, true);
    }
}
