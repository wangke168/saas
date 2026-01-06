<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'scenic_spot_id',
        'ota_product_code',
        'product_name',
        'product_mode',
        'stay_days',
        'sale_start_date',
        'sale_end_date',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'stay_days' => 'integer',
            'status' => 'integer',
            'sale_start_date' => 'date',
            'sale_end_date' => 'date',
        ];
    }

    /**
     * 序列化日期格式为 Y-m-d（与前端日期选择器的 value-format 一致）
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * 所属景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    /**
     * 打包清单
     */
    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'sales_product_id');
    }

    /**
     * 价格日历
     */
    public function prices(): HasMany
    {
        return $this->hasMany(SalesProductPrice::class, 'sales_product_id');
    }

    /**
     * 订单列表
     */
    public function orders(): HasMany
    {
        return $this->hasMany(SystemPkgOrder::class, 'sales_product_id');
    }

    /**
     * 判断是否上架
     */
    public function isActive(): bool
    {
        return $this->status === 1;
    }
}

