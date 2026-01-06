<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResHotel extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 模型启动方法
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($resHotel) {
            if (empty($resHotel->code)) {
                $resHotel->code = static::generateUniqueCode();
            }
        });
    }

    protected $fillable = [
        'code',
        'scenic_spot_id',
        'software_provider_id',
        'name',
        'external_hotel_id',
        'address',
        'contact_phone',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
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
     * 所属软件服务商
     */
    public function softwareProvider(): BelongsTo
    {
        return $this->belongsTo(SoftwareProvider::class);
    }

    /**
     * 房型列表
     */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(ResRoomType::class, 'hotel_id');
    }

    /**
     * 每日价格库存列表
     */
    public function dailyStocks(): HasMany
    {
        return $this->hasMany(ResHotelDailyStock::class, 'hotel_id');
    }

    /**
     * 判断是否为自控库存
     */
    public function isSelfControlled(): bool
    {
        return empty($this->software_provider_id);
    }

    /**
     * 判断是否为第三方库存
     */
    public function isThirdParty(): bool
    {
        return !empty($this->software_provider_id);
    }

    /**
     * 生成唯一的酒店编码（6位：RH + 4位数字）
     */
    protected static function generateUniqueCode(): string
    {
        // 查找当前最大编号（以RH开头，长度为6位）
        $maxCode = static::where('code', 'like', 'RH%')
            ->whereRaw('LENGTH(code) = 6')
            ->orderByRaw('CAST(SUBSTRING(code, 3) AS UNSIGNED) DESC')
            ->value('code');
        
        if ($maxCode) {
            $nextNumber = (int)substr($maxCode, 2) + 1;
        } else {
            $nextNumber = 1;
        }
        
        // 确保不超过4位数
        if ($nextNumber > 9999) {
            throw new \Exception('打包用酒店编号已达到上限（9999）');
        }
        
        // 如果生成的编号已存在，继续递增（处理并发情况）
        do {
            $code = 'RH' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            $exists = static::where('code', $code)->exists();
            if ($exists) {
                $nextNumber++;
                if ($nextNumber > 9999) {
                    throw new \Exception('打包用酒店编号已达到上限（9999）');
                }
            }
        } while ($exists);
        
        return $code;
    }
}

