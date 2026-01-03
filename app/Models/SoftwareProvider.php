<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SoftwareProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'api_type',
        'api_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 景区列表（多对多关系）
     */
    public function scenicSpots(): BelongsToMany
    {
        return $this->belongsToMany(ScenicSpot::class, 'scenic_spot_software_providers');
    }
    
    /**
     * 景区列表（旧的一对多关系，保留用于向后兼容）
     * @deprecated 使用 scenicSpots() 多对多关系替代
     */
    public function scenicSpotsOld(): HasMany
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
