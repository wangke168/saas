<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderExternalPushLog extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const PUSH_TYPE_CREATE = 'create';

    public const PUSH_TYPE_STATUS_UPDATE = 'status_update';

    public const ORDER_TYPE_ORDER = 'order';

    public const ORDER_TYPE_PKG_ORDER = 'pkg_order';

    protected $fillable = [
        'order_type',
        'order_id',
        'order_no',
        'scenic_spot_id',
        'push_type',
        'route_order_status',
        'endpoint',
        'request_payload',
        'http_status',
        'response_body',
        'status',
        'attempt',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_body' => 'array',
            'route_order_status' => 'integer',
            'http_status' => 'integer',
            'attempt' => 'integer',
        ];
    }

    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }
}
