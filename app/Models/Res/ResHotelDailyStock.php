<?php

namespace App\Models\Res;

use App\Enums\PriceSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResHotelDailyStock extends Model
{
    use HasFactory;

    protected $table = 'res_hotel_daily_stock';

    protected $fillable = [
        'hotel_id',
        'room_type_id',
        'biz_date',
        'cost_price',
        'sale_price',
        'stock_total',
        'stock_sold',
        'stock_available',
        'version',
        'source',
        'is_closed',
    ];

    protected function casts(): array
    {
        return [
            'biz_date' => 'date',
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock_total' => 'integer',
            'stock_sold' => 'integer',
            'stock_available' => 'integer',
            'version' => 'integer',
            'source' => PriceSource::class,
            'is_closed' => 'boolean',
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
     * 获取可用库存（如果未设置，则计算）
     * 
     * 注意：如果 stock_available 字段值为 NULL，则计算 stock_total - stock_sold
     * 如果字段值不为 NULL，直接返回字段值
     */
    public function getStockAvailableAttribute($value): int
    {
        // 如果字段值不为空，直接返回（普通字段的值）
        if ($value !== null && $value !== '') {
            return (int) $value;
        }
        
        // 如果字段值为空，计算并返回（向后兼容）
        return max(0, ($this->attributes['stock_total'] ?? 0) - ($this->attributes['stock_sold'] ?? 0));
    }

    /**
     * 判断是否可编辑（API来源的数据不允许编辑）
     */
    public function isEditable(): bool
    {
        return $this->source !== PriceSource::API;
    }
}
