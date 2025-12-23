<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ResourceProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'api_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 配置
     */
    public function config(): HasOne
    {
        return $this->hasOne(ResourceConfig::class);
    }

    /**
     * 酒店列表
     */
    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    /**
     * 订单列表
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
