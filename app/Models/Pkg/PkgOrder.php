<?php

namespace App\Models\Pkg;

use App\Enums\PkgOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PkgOrder extends Model
{
    use HasFactory;

    protected $table = 'pkg_orders';

    protected $fillable = [
        'order_no',
        'ota_order_no',
        'ota_platform_id',
        'pkg_product_id',
        'hotel_id',
        'room_type_id',
        'check_in_date',
        'check_out_date',
        'stay_days',
        'total_amount',
        'settlement_amount',
        'contact_name',
        'contact_phone',
        'contact_email',
        'status',
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
            'status' => PkgOrderStatus::class,
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
        return $this->belongsTo(\App\Models\OtaPlatform::class, 'ota_platform_id');
    }

    /**
     * 打包产品
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(PkgProduct::class, 'pkg_product_id');
    }

    /**
     * 酒店
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Res\ResHotel::class, 'hotel_id');
    }

    /**
     * 房型
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Res\ResRoomType::class, 'room_type_id');
    }

    /**
     * 订单明细
     */
    public function items(): HasMany
    {
        return $this->hasMany(PkgOrderItem::class, 'order_id');
    }

    /**
     * 异常订单
     */
    public function exceptionOrders(): HasMany
    {
        return $this->hasMany(PkgExceptionOrder::class, 'order_id');
    }
}
