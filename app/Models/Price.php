<?php

namespace App\Models;

use App\Enums\PriceSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'room_type_id',
        'date',
        'market_price',
        'settlement_price',
        'sale_price',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'market_price' => 'decimal:2',
            'settlement_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'source' => PriceSource::class,
        ];
    }

    /**
     * 所属产品
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * 所属房型
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
