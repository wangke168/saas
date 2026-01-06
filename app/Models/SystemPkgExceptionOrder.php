<?php

namespace App\Models;

use App\Enums\SystemPkgExceptionOrderStatus;
use App\Enums\SystemPkgExceptionOrderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemPkgExceptionOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'exception_type',
        'exception_message',
        'exception_data',
        'status',
        'handler_id',
        'resolved_at',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'exception_type' => SystemPkgExceptionOrderType::class,
            'exception_data' => 'array',
            'status' => SystemPkgExceptionOrderStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * 所属订单
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SystemPkgOrder::class, 'order_id');
    }

    /**
     * 处理人
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handler_id');
    }

    /**
     * 判断是否待处理
     */
    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    /**
     * 判断是否已解决
     */
    public function isResolved(): bool
    {
        return $this->status === 'RESOLVED';
    }
}

