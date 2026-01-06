<?php

namespace App\Models\Pkg;

use App\Enums\PkgOrderItemStatus;
use App\Enums\PkgOrderItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PkgOrderItem extends Model
{
    use HasFactory;

    protected $table = 'pkg_order_items';

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
            'item_type' => PkgOrderItemType::class,
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'status' => PkgOrderItemStatus::class,
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * 主订单
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PkgOrder::class, 'order_id');
    }
}
