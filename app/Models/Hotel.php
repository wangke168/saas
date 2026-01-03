<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hotel extends Model
{
    use HasFactory, SoftDeletes;

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

    protected $fillable = [
        'scenic_spot_id',
        'name',
        'code',
        'address',
        'contact_phone',
        'is_connected',
        'external_id',
        'external_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_connected' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 生成唯一的酒店编码（6位：H + 5位数字）
     */
    protected static function generateUniqueCode(): string
    {
        // 查找当前最大编号（以H开头，长度为6位）
        $maxCode = static::where('code', 'like', 'H%')
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
            throw new \Exception('酒店编号已达到上限（99999）');
        }
        
        // 如果生成的编号已存在，继续递增（处理并发情况）
        do {
            $code = 'H' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            $exists = static::where('code', $code)->exists();
            if ($exists) {
                $nextNumber++;
                if ($nextNumber > 99999) {
                    throw new \Exception('酒店编号已达到上限（99999）');
                }
            }
        } while ($exists);
        
        return $code;
    }

    /**
     * 所属景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    /**
     * 获取系统服务商（通过景区关联）
     */
    public function getSoftwareProviderAttribute(): ?SoftwareProvider
    {
        return $this->scenicSpot->softwareProvider ?? null;
    }

    /**
     * 房型列表
     */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }
}