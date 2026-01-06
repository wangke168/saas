<?php

namespace App\Models\Res;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResRoomType extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'res_room_types';

    /**
     * 模型启动方法
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($roomType) {
            if (empty($roomType->code)) {
                $roomType->code = static::generateUniqueCode();
            }
        });
    }

    /**
     * 生成唯一的房型编码（格式：R + 5位数字，如 R00001）
     */
    protected static function generateUniqueCode(): string
    {
        do {
            // 查找当前最大编号（以R开头，长度为6位）
            $maxCode = static::where('code', 'like', 'R%')
                ->whereRaw('LENGTH(code) = 6')
                ->orderByRaw('CAST(SUBSTRING(code, 2) AS UNSIGNED) DESC')
                ->value('code');
            
            if ($maxCode) {
                $nextNumber = intval(substr($maxCode, 1)) + 1;
            } else {
                $nextNumber = 1;
            }
            
            $code = 'R' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        } while (static::where('code', $code)->exists());
        
        return $code;
    }

    protected $fillable = [
        'hotel_id',
        'name',
        'code',
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
            'max_occupancy' => 'integer',
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
     * 每日价格库存
     */
    public function dailyStocks(): HasMany
    {
        return $this->hasMany(ResHotelDailyStock::class, 'room_type_id');
    }
}
