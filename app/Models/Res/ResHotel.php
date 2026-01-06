<?php

namespace App\Models\Res;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResHotel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'res_hotels';

    /**
     * 模型启动方法
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($hotel) {
            if (empty($hotel->code)) {
                $hotel->code = static::generateUniqueCode();
            }
        });
    }

    /**
     * 生成唯一的酒店编码（格式：H + 5位数字，如 H00001）
     */
    protected static function generateUniqueCode(): string
    {
        do {
            // 查找当前最大编号（以H开头，长度为6位）
            $maxCode = static::where('code', 'like', 'H%')
                ->whereRaw('LENGTH(code) = 6')
                ->orderByRaw('CAST(SUBSTRING(code, 2) AS UNSIGNED) DESC')
                ->value('code');
            
            if ($maxCode) {
                $nextNumber = intval(substr($maxCode, 1)) + 1;
            } else {
                $nextNumber = 1;
            }
            
            $code = 'H' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        } while (static::where('code', $code)->exists());
        
        return $code;
    }

    protected $fillable = [
        'scenic_spot_id',
        'software_provider_id',
        'name',
        'code',
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
        return $this->belongsTo(\App\Models\ScenicSpot::class, 'scenic_spot_id');
    }

    /**
     * 软件服务商
     */
    public function softwareProvider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\SoftwareProvider::class, 'software_provider_id');
    }

    /**
     * 房型列表
     */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(ResRoomType::class, 'hotel_id');
    }

    /**
     * 每日价格库存
     */
    public function dailyStocks(): HasMany
    {
        return $this->hasMany(ResHotelDailyStock::class, 'hotel_id');
    }
}
