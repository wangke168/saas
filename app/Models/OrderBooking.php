<?php

namespace App\Models;

use App\Enums\GuestIdType;
use App\Enums\OrderBookingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderBooking extends Model
{
    protected $fillable = [
        'booking_no',
        'order_entitlement_id',
        'order_id',
        'presale_product_id',
        'package_product_id',
        'hotel_id',
        'room_type_id',
        'check_in_date',
        'check_out_date',
        'guest_name',
        'guest_phone',
        'guest_id_type',
        'guest_id_card',
        'package_sale_price',
        'base_price',
        'surcharge_amount',
        'status',
        'payment_no',
        'paid_at',
        'payment_expires_at',
        'fulfilled_order_id',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'package_sale_price' => 'decimal:2',
            'base_price' => 'decimal:2',
            'surcharge_amount' => 'decimal:2',
            'guest_id_type' => GuestIdType::class,
            'status' => OrderBookingStatus::class,
            'paid_at' => 'datetime',
            'payment_expires_at' => 'datetime',
        ];
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(OrderEntitlement::class, 'order_entitlement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function presaleProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'presale_product_id');
    }

    public function packageProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'package_product_id');
    }

    public function fulfilledOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'fulfilled_order_id');
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
