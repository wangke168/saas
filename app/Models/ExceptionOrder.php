<?php

namespace App\Models;

use App\Enums\ExceptionOrderStatus;
use App\Enums\ExceptionOrderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExceptionOrder extends Model
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
    ];

    protected function casts(): array
    {
        return [
            'exception_type' => ExceptionOrderType::class,
            'exception_data' => 'array',
            'status' => ExceptionOrderStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * 所属订单
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * 处理人
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handler_id');
    }
}
