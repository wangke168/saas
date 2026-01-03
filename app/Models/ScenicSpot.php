<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScenicSpot extends Model
{
    use HasFactory;

    /**
     * 模型启动方法
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($scenicSpot) {
            if (empty($scenicSpot->code)) {
                $scenicSpot->code = static::generateUniqueCode();
            }
        });
    }

    /**
     * 生成唯一的景区编码
     */
    protected static function generateUniqueCode(): string
    {
        do {
            $code = 'SS' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        } while (static::where('code', $code)->exists());
        
        return $code;
    }

    protected $fillable = [
        'name',
        'code',
        'description',
        'address',
        'contact_phone',
        'software_provider_id',
        'resource_provider_id',
        'resource_config_id',
        'is_system_connected',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system_connected' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 系统服务商（多对多关系，一个景区可以有多个服务商）
     */
    public function softwareProviders(): BelongsToMany
    {
        return $this->belongsToMany(SoftwareProvider::class, 'scenic_spot_software_providers');
    }
    
    /**
     * 系统服务商（旧的一对一关系，保留用于向后兼容）
     * @deprecated 使用 softwareProviders() 多对多关系替代
     */
    public function softwareProvider(): BelongsTo
    {
        return $this->belongsTo(SoftwareProvider::class);
    }

    /**
     * 资源方（直接关联，用于快速查询）
     */
    public function resourceProvider(): BelongsTo
    {
        return $this->belongsTo(ResourceProvider::class);
    }

    /**
     * 资源方（多对多关联，一个景区可以属于多个资源方）
     */
    public function resourceProviders(): BelongsToMany
    {
        return $this->belongsToMany(ResourceProvider::class, 'resource_provider_scenic_spots');
    }

    /**
     * 系统配置列表（该景区在系统中的配置，一个景区可以有多个服务商的配置）
     */
    public function resourceConfigs(): HasMany
    {
        return $this->hasMany(ResourceConfig::class);
    }
    
    /**
     * 系统配置（旧的一对一关系，保留用于向后兼容）
     * @deprecated 使用 resourceConfigs() 一对多关系替代
     */
    public function resourceConfig(): BelongsTo
    {
        return $this->belongsTo(ResourceConfig::class);
    }

    /**
     * 绑定的用户（保留用于兼容，但不再使用）
     * @deprecated 使用 resourceProviders 关联替代
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_scenic_spots');
    }

    /**
     * 酒店列表
     */
    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    /**
     * 产品列表
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
