<?php

namespace App\Models;

use App\Enums\PriceRuleType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'type',
        'weekdays',
        'start_date',
        'end_date',
        'market_price_adjustment',
        'settlement_price_adjustment',
        'sale_price_adjustment',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => PriceRuleType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'market_price_adjustment' => 'decimal:2',
            'settlement_price_adjustment' => 'decimal:2',
            'sale_price_adjustment' => 'decimal:2',
            'is_active' => 'boolean',
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
     * 规则明细
     */
    public function items(): HasMany
    {
        return $this->hasMany(PriceRuleItem::class);
    }
}
