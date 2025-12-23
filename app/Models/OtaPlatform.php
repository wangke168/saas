<?php

namespace App\Models;

use App\Enums\OtaPlatform as OtaPlatformEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OtaPlatform extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'code' => OtaPlatformEnum::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * OTA配置
     */
    public function config(): HasOne
    {
        return $this->hasOne(OtaConfig::class);
    }

    /**
     * OTA产品列表
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
