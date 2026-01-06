<?php

namespace App\Models\Pkg;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PkgProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pkg_products';

    protected $fillable = [
        'scenic_spot_id',
        'product_code',
        'product_name',
        'stay_days',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'stay_days' => 'integer',
            'status' => 'integer',
        ];
    }

    /**
     * 所属景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ScenicSpot::class, 'scenic_spot_id');
    }

    /**
     * 必选门票关联
     */
    public function bundleItems(): HasMany
    {
        return $this->hasMany(PkgProductBundleItem::class, 'pkg_product_id');
    }

    /**
     * 酒店房型关联（关键变更）
     */
    public function hotelRoomTypes(): HasMany
    {
        return $this->hasMany(PkgProductHotelRoomType::class, 'pkg_product_id');
    }

    /**
     * 预计算价格
     */
    public function dailyPrices(): HasMany
    {
        return $this->hasMany(PkgProductDailyPrice::class, 'pkg_product_id');
    }

    /**
     * 获取关联的酒店（通过房型关联）
     */
    public function hotels(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Res\ResHotel::class,
            'pkg_product_hotel_room_types',
            'pkg_product_id',
            'hotel_id'
        )->distinct();
    }

    /**
     * 获取关联的房型（通过房型关联）
     */
    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Res\ResRoomType::class,
            'pkg_product_hotel_room_types',
            'pkg_product_id',
            'room_type_id'
        )->distinct();
    }

    /**
     * 订单列表
     */
    public function orders(): HasMany
    {
        return $this->hasMany(PkgOrder::class, 'pkg_product_id');
    }
}
