<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'software_provider_id',
        'scenic_spot_id',
        'username',
        'password',
        'api_url',
        'environment',
        'extra_config',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'extra_config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 系统服务商
     */
    public function softwareProvider(): BelongsTo
    {
        return $this->belongsTo(SoftwareProvider::class);
    }

    /**
     * 景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    /**
     * 获取同步模式配置
     */
    public function getSyncModeAttribute(): array
    {
        $extraConfig = $this->extra_config ?? [];
        return [
            'inventory' => $extraConfig['sync_mode']['inventory'] ?? 'manual',
            'price' => $extraConfig['sync_mode']['price'] ?? 'manual',
            'order' => $extraConfig['sync_mode']['order'] ?? 'manual',
        ];
    }

    /**
     * 库存是否推送
     */
    public function isInventoryPush(): bool
    {
        return $this->sync_mode['inventory'] === 'push';
    }

    /**
     * 价格是否推送
     */
    public function isPricePush(): bool
    {
        return $this->sync_mode['price'] === 'push';
    }

    /**
     * 订单是否系统直连
     */
    public function isOrderAuto(): bool
    {
        return $this->sync_mode['order'] === 'auto';
    }
}

    /**
     * 景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    /**
     * 获取同步模式配置
     */
    public function getSyncModeAttribute(): array
    {
        $extraConfig = $this->extra_config ?? [];
        return [
            'inventory' => $extraConfig['sync_mode']['inventory'] ?? 'manual',
            'price' => $extraConfig['sync_mode']['price'] ?? 'manual',
            'order' => $extraConfig['sync_mode']['order'] ?? 'manual',
        ];
    }

    /**
     * 库存是否推送
     */
    public function isInventoryPush(): bool
    {
        return $this->sync_mode['inventory'] === 'push';
    }

    /**
     * 价格是否推送
     */
    public function isPricePush(): bool
    {
        return $this->sync_mode['price'] === 'push';
    }

    /**
     * 订单是否系统直连
     */
    public function isOrderAuto(): bool
    {
        return $this->sync_mode['order'] === 'auto';
    }
}

    /**
     * 景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    /**
     * 获取同步模式配置
     */
    public function getSyncModeAttribute(): array
    {
        $extraConfig = $this->extra_config ?? [];
        return [
            'inventory' => $extraConfig['sync_mode']['inventory'] ?? 'manual',
            'price' => $extraConfig['sync_mode']['price'] ?? 'manual',
            'order' => $extraConfig['sync_mode']['order'] ?? 'manual',
        ];
    }

    /**
     * 库存是否推送
     */
    public function isInventoryPush(): bool
    {
        return $this->sync_mode['inventory'] === 'push';
    }

    /**
     * 价格是否推送
     */
    public function isPricePush(): bool
    {
        return $this->sync_mode['price'] === 'push';
    }

    /**
     * 订单是否系统直连
     */
    public function isOrderAuto(): bool
    {
        return $this->sync_mode['order'] === 'auto';
    }
}

    /**
     * 景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    /**
     * 获取同步模式配置
     */
    public function getSyncModeAttribute(): array
    {
        $extraConfig = $this->extra_config ?? [];
        return [
            'inventory' => $extraConfig['sync_mode']['inventory'] ?? 'manual',
            'price' => $extraConfig['sync_mode']['price'] ?? 'manual',
            'order' => $extraConfig['sync_mode']['order'] ?? 'manual',
        ];
    }

    /**
     * 库存是否推送
     */
    public function isInventoryPush(): bool
    {
        return $this->sync_mode['inventory'] === 'push';
    }

    /**
     * 价格是否推送
     */
    public function isPricePush(): bool
    {
        return $this->sync_mode['price'] === 'push';
    }

    /**
     * 订单是否系统直连
     */
    public function isOrderAuto(): bool
    {
        return $this->sync_mode['order'] === 'auto';
    }
}