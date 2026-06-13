<?php

namespace App\Models;

use App\Enums\OrderEntitlementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEntitlement extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'line_no',
        'entitlement_no',
        'status',
        'base_price',
        'order_booking_id',
        'booked_at',
        'ota_consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderEntitlementStatus::class,
            'base_price' => 'decimal:2',
            'booked_at' => 'datetime',
            'ota_consumed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(OrderBooking::class, 'order_booking_id');
    }
}
