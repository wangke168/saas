<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResRoomType extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 模型启动方法
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($resRoomType) {
            if (empty($resRoomType->code)) {
                $resRoomType->code = static::generateUniqueCode();
            }
        });
    }

    protected $fillable = [
        'code',
        'hotel_id',
        'name',
        'external_room_id',
        'max_occupancy',
        'bed_type',
        'room_area',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'room_area' => 'decimal:2',
            'is_active' => 'boolean',
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
     * 每日价格库存列表
     */
    public function dailyStocks(): HasMany
    {
        return $this->hasMany(ResHotelDailyStock::class, 'room_type_id');
    }

    /**
     * 生成唯一的房型编码（6位：RR + 4位数字）
     */
    protected static function generateUniqueCode(): string
    {
        // 查找当前最大编号（以RR开头，长度为6位）
        $maxCode = static::where('code', 'like', 'RR%')
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
            throw new \Exception('打包用房型编号已达到上限（9999）');
        }
        
        // 如果生成的编号已存在，继续递增（处理并发情况）
        do {
            $code = 'RR' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            $exists = static::where('code', $code)->exists();
            if ($exists) {
                $nextNumber++;
                if ($nextNumber > 9999) {
                    throw new \Exception('打包用房型编号已达到上限（9999）');
                }
            }
        } while ($exists);
        
        return $code;
    }
}

