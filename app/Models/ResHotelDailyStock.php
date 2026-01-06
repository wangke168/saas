<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResHotelDailyStock extends Model
{
    use HasFactory;

    /**
     * 表名（单数形式）
     */
    protected $table = 'res_hotel_daily_stock';

    protected $fillable = [
        'hotel_id',
        'room_type_id',
        'biz_date',
        'cost_price',
        'sale_price',
        'stock_total',
        'stock_sold',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'biz_date' => 'date',
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
        ];
    }

    /**
     * 所属酒店
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(ResHotel::class, 'hotel_id');
    }

    /**
     * 所属房型
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(ResRoomType::class, 'room_type_id');
    }

    /**
     * 获取可用库存（计算字段，数据库已生成）
     */
    public function getStockAvailableAttribute(): int
    {
        return $this->stock_total - $this->stock_sold;
    }
}

