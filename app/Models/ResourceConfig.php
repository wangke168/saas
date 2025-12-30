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

    /**
     * 获取认证类型
     */
    public function getAuthType(): string
    {
        return $this->extra_config['auth']['type'] ?? 'username_password';
    }

    /**
     * 获取 AppKey
     */
    public function getAppKey(): ?string
    {
        return $this->extra_config['auth']['appkey'] ?? $this->extra_config['auth']['app_id'] ?? null;
    }

    /**
     * 获取 AppSecret
     */
    public function getAppSecret(): ?string
    {
        return $this->extra_config['auth']['appsecret'] ?? null;
    }

    /**
     * 获取 Token
     */
    public function getToken(): ?string
    {
        return $this->extra_config['auth']['token'] 
            ?? $this->extra_config['auth']['access_token'] 
            ?? null;
    }

    /**
     * 获取认证参数（用于匹配识别景区）
     * 根据认证类型返回对应的标识符
     */
    public function getAuthIdentifier(): ?string
    {
        return match($this->getAuthType()) {
            'username_password' => $this->username,
            'appkey_secret' => $this->getAppKey(),
            'token' => $this->getToken(),
            default => $this->username,
        };
    }

    /**
     * 获取认证配置（用于创建客户端）
     */
    public function getAuthConfig(): array
    {
        $authType = $this->getAuthType();
        
        $config = [
            'type' => $authType,
            'api_url' => $this->api_url,
        ];

        return match($authType) {
            'username_password' => array_merge($config, [
                'username' => $this->username,
                'password' => $this->password,
            ]),
            'appkey_secret' => array_merge($config, [
                'appkey' => $this->getAppKey(),
                'appsecret' => $this->getAppSecret(),
            ]),
            'token' => array_merge($config, [
                'token' => $this->getToken(),
            ]),
            default => array_merge($config, [
                'username' => $this->username,
                'password' => $this->password,
            ]),
        };
    }
}