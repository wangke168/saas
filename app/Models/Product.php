<?php

namespace App\Models;

use App\Enums\PriceSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'scenic_spot_id',
        'name',
        'code',
        'description',
        'price_source',
        'is_active',
        'stay_days',
    ];

    protected function casts(): array
    {
        return [
            'price_source' => PriceSource::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * 所属景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    /**
     * 价格列表
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /**
     * 加价规则列表
     */
    public function priceRules(): HasMany
    {
        return $this->hasMany(PriceRule::class);
    }

    /**
     * OTA产品关联
     */
    public function otaProducts(): HasMany
    {
        return $this->hasMany(OtaProduct::class);
    }

    /**
     * 订单列表
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
