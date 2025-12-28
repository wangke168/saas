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

    /**
     * 模型启动方法
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($product) {
            // 如果 code 为空，自动生成唯一编码
            if (empty($product->code)) {
                $product->code = static::generateUniqueCode();
            }
        });
    }

    /**
     * 生成唯一的产品编码
     * 格式：PRD + YYYYMMDD + 6位随机字符串（大写字母和数字）
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = 'PRD' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    protected $fillable = [
        'scenic_spot_id',
        'name',
        'code',
        'external_code',
        'description',
        'price_source',
        'is_active',
        'stay_days',
        'sale_start_date',
        'sale_end_date',
    ];

    protected function casts(): array
    {
        return [
            'price_source' => PriceSource::class,
            'is_active' => 'boolean',
            'sale_start_date' => 'date',
            'sale_end_date' => 'date',
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
