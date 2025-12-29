<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SoftwareProvider extends Model
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
     * 景区列表
     */
    public function scenicSpots(): HasMany
    {
        return $this->hasMany(ScenicSpot::class, 'software_provider_id');
    }

    /**
     * 系统配置列表（景区专用配置）
     */
    public function resourceConfigs(): HasMany
    {
        return $this->hasMany(ResourceConfig::class, 'software_provider_id');
    }
}
