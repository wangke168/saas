<?php

namespace App\Models\Pkg;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

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
        'sale_start_date',
        'sale_end_date',
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

    /**
     * 检查指定日期是否在销售日期范围内
     * 
     * @param string|Carbon $date 日期（Y-m-d格式或Carbon对象）
     * @return bool
     */
    public function isDateInSaleRange($date): bool
    {
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }
        
        // 如果开始日期不为空，检查是否在开始日期之后
        if ($this->sale_start_date && $date->lt($this->sale_start_date)) {
            return false;
        }
        
        // 如果结束日期不为空，检查是否在结束日期之前
        if ($this->sale_end_date && $date->gt($this->sale_end_date)) {
            return false;
        }
        
        return true;
    }

    /**
     * 获取有效的销售日期范围（用于价格计算和OTA推送）
     * 
     * @return array ['start' => Carbon|null, 'end' => Carbon|null]
     */
    public function getEffectiveSaleDateRange(): array
    {
        $today = Carbon::today();
        
        // 默认计算未来60天
        $defaultEnd = $today->copy()->addDays(59);
        
        $start = $this->sale_start_date 
            ? max($today, $this->sale_start_date)  // 取今天和开始日期的较大值
            : $today;
        
        $end = $this->sale_end_date 
            ? min($defaultEnd, $this->sale_end_date)  // 取60天后和结束日期的较小值
            : $defaultEnd;
        
        // 如果开始日期大于结束日期，返回空范围
        if ($start->gt($end)) {
            return ['start' => null, 'end' => null];
        }
        
        return ['start' => $start, 'end' => $end];
    }
}
