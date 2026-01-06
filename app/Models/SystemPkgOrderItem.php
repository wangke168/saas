<?php

namespace App\Models;

use App\Enums\SystemPkgOrderItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemPkgOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'item_type',
        'resource_id',
        'resource_name',
        'quantity',
        'unit_price',
        'total_price',
        'status',
        'resource_order_no',
        'error_message',
        'retry_count',
        'max_retries',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'status' => SystemPkgOrderItemStatus::class,
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'processed_at' => 'datetime',
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
     * 判断是否可以重试
     */
    public function canRetry(): bool
    {
        return $this->retry_count < $this->max_retries;
    }

    /**
     * 判断是否处理成功
     */
    public function isSuccess(): bool
    {
        return $this->status === 'SUCCESS';
    }

    /**
     * 判断是否处理失败
     */
    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }
}

