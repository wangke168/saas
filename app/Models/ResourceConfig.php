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
     * 获取API地址（从服务商获取）
     * 使用访问器自动从software_providers表获取api_url
     */
    public function getApiUrlAttribute(): string
    {
        // 如果关系未加载，且有 software_provider_id，则自动加载
        // 这可以解决队列序列化后关系丢失的问题
        if (!$this->relationLoaded('softwareProvider') && $this->software_provider_id) {
            $this->load('softwareProvider');
        }
        
        // 优先从服务商获取API地址
        if ($this->softwareProvider) {
            $apiUrl = $this->softwareProvider->api_url;
            if (!empty($apiUrl)) {
                return $apiUrl;
            }
        }
        
        // 向后兼容：如果服务商没有配置，尝试从extra_config获取（用于临时配置）
        if (isset($this->extra_config['api_url_override'])) {
            return $this->extra_config['api_url_override'];
        }
        
        // 向后兼容：如果是从环境变量创建的临时配置，尝试从attributes获取
        if (isset($this->attributes['api_url'])) {
            return $this->attributes['api_url'];
        }
        
        return '';
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
     * 获取自定义参数（已解密）
     */
    public function getCustomParams(): array
    {
        $params = $this->extra_config['auth']['params'] ?? [];
        
        // 解密敏感参数
        $decryptedParams = [];
        foreach ($params as $key => $value) {
            // 如果值是加密的（以encrypted:开头），则解密
            if (is_string($value) && str_starts_with($value, 'encrypted:')) {
                try {
                    $encryptedValue = substr($value, 10); // 移除 'encrypted:' 前缀
                    $decryptedParams[$key] = decrypt($encryptedValue);
                } catch (\Exception $e) {
                    // 解密失败，使用原值
                    $decryptedParams[$key] = $value;
                }
            } else {
                $decryptedParams[$key] = $value;
            }
        }
        
        return $decryptedParams;
    }

    /**
     * 判断参数名是否为敏感参数（需要加密）
     */
    protected function isSensitiveParam(string $paramName): bool
    {
        $sensitiveKeywords = ['password', 'pwd', 'secret', 'key', 'token', 'auth'];
        $paramNameLower = strtolower($paramName);
        
        foreach ($sensitiveKeywords as $keyword) {
            if (str_contains($paramNameLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 加密参数值
     */
    protected function encryptParamValue(string $value): string
    {
        return 'encrypted:' . encrypt($value);
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
     * 支持自定义参数名和参数值
     */
    public function getAuthConfig(): array
    {
        $authType = $this->getAuthType();
        
        $config = [
            'type' => $authType,
            'api_url' => $this->api_url, // 使用访问器，自动从服务商获取
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
            'custom' => array_merge($config, [
                // 自定义参数：返回解密后的参数
                'params' => $this->getCustomParams(),
            ]),
            default => array_merge($config, [
                'username' => $this->username,
                'password' => $this->password,
            ]),
        };
    }
}