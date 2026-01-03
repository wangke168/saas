<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoomType extends Model
{
    use HasFactory, SoftDeletes;

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

    protected $fillable = [
        'hotel_id',
        'name',
        'code',
        'max_occupancy',
        'description',
        'external_id',
        'external_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 生成唯一的房型编码（6位：R + 5位数字）
     */
    protected static function generateUniqueCode(): string
    {
        // 查找当前最大编号（以R开头，长度为6位）
        $maxCode = static::where('code', 'like', 'R%')
            ->whereRaw('LENGTH(code) = 6')
            ->orderByRaw('CAST(SUBSTRING(code, 2) AS UNSIGNED) DESC')
            ->value('code');
        
        if ($maxCode) {
            $nextNumber = (int)substr($maxCode, 1) + 1;
        } else {
            $nextNumber = 1;
        }
        
        // 确保不超过5位数
        if ($nextNumber > 99999) {
            throw new \Exception('房型编号已达到上限（99999）');
        }
        
        // 如果生成的编号已存在，继续递增（处理并发情况）
        do {
            $code = 'R' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            $exists = static::where('code', $code)->exists();
            if ($exists) {
                $nextNumber++;
                if ($nextNumber > 99999) {
                    throw new \Exception('房型编号已达到上限（99999）');
                }
            }
        } while ($exists);
        
        return $code;
    }

    /**
     * 所属酒店
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * 库存列表
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    /**
     * 价格列表
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
