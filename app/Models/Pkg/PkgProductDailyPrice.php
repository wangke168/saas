<?php

namespace App\Models\Pkg;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PkgProductDailyPrice extends Model
{
    use HasFactory;

    protected $table = 'pkg_product_daily_prices';

    protected $fillable = [
        'pkg_product_id',
        'hotel_id',
        'room_type_id',
        'biz_date',
        'sale_price',
        'cost_price',
        'composite_code',
        'last_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'biz_date' => 'date',
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'last_updated_at' => 'datetime',
        ];
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
}
