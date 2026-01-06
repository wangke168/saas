<?php

namespace App\Models\Pkg;

use App\Enums\PkgExceptionOrderStatus;
use App\Enums\PkgExceptionOrderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PkgExceptionOrder extends Model
{
    use HasFactory;

    protected $table = 'pkg_exception_orders';

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
            'exception_type' => PkgExceptionOrderType::class,
            'exception_data' => 'array',
            'status' => PkgExceptionOrderStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * 订单
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PkgOrder::class, 'order_id');
    }

    /**
     * 处理人
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'handler_id');
    }
}
