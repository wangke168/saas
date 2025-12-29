<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * 用户绑定的景区（保留用于兼容，但不再使用）
     * @deprecated 使用 resourceProviders 和 accessibleScenicSpots 替代
     */
    public function scenicSpots(): BelongsToMany
    {
        return $this->belongsToMany(ScenicSpot::class, 'user_scenic_spots');
    }

    /**
     * 用户关联的资源方
     */
    public function resourceProviders(): BelongsToMany
    {
        return $this->belongsToMany(ResourceProvider::class, 'user_resource_providers');
    }

    /**
     * 获取用户可访问的所有景区（通过资源方）
     */
    public function accessibleScenicSpots()
    {
        if ($this->isAdmin()) {
            return ScenicSpot::query();
        }

        // 运营人员：获取所属资源方下的所有景区
        $resourceProviderIds = $this->resourceProviders->pluck('id');
        return ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
            $query->whereIn('resource_providers.id', $resourceProviderIds);
        });
    }

    /**
     * 是否为超级管理员
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    /**
     * 是否为运营
     */
    public function isOperator(): bool
    {
        return $this->role === UserRole::OPERATOR;
    }
}
