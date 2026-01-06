<?php

namespace App\Models;

use App\Enums\SystemPkgOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemPkgOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_no',
        'ota_order_no',
        'ota_platform_id',
        'sales_product_id',
        'ota_product_code',
        'check_in_date',
        'check_out_date',
        'stay_days',
        'total_amount',
        'settlement_amount',
        'contact_name',
        'contact_phone',
        'contact_email',
        'guest_count',
        'guest_info',
        'status',
        'resource_order_no',
        'paid_at',
        'confirmed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'stay_days' => 'integer',
            'total_amount' => 'decimal:2',
            'settlement_amount' => 'decimal:2',
            'guest_count' => 'integer',
            'guest_info' => 'array',
            'status' => SystemPkgOrderStatus::class,
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * OTA平台
     */
    public function otaPlatform(): BelongsTo
    {
        return $this->belongsTo(OtaPlatform::class, 'ota_platform_id');
    }

    /**
     * 销售产品
     */
    public function salesProduct(): BelongsTo
    {
        return $this->belongsTo(SalesProduct::class, 'sales_product_id');
    }

    /**
     * 订单拆单明细
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(SystemPkgOrderItem::class, 'order_id');
    }

    /**
     * 异常订单
     */
    public function exceptionOrders(): HasMany
    {
        return $this->hasMany(SystemPkgExceptionOrder::class, 'order_id');
    }

    /**
     * 门票订单项
     */
    public function ticketItems(): HasMany
    {
        return $this->orderItems()->where('item_type', 'TICKET');
    }

    /**
     * 酒店订单项
     */
    public function hotelItems(): HasMany
    {
        return $this->orderItems()->where('item_type', 'HOTEL');
    }
}

