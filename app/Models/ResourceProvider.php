<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResourceProvider extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'contact_name',
        'contact_phone',
        'contact_email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 关联的用户（运营人员）
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_resource_providers');
    }

    /**
     * 关联的景区
     */
    public function scenicSpots(): BelongsToMany
    {
        return $this->belongsToMany(ScenicSpot::class, 'resource_provider_scenic_spots');
    }

    /**
     * 生成唯一的资源方编码
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = 'RP' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        } while (static::where('code', $code)->exists());
        
        return $code;
    }
}

{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'contact_name',
        'contact_phone',
        'contact_email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 关联的用户（运营人员）
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_resource_providers');
    }

    /**
     * 关联的景区
     */
    public function scenicSpots(): BelongsToMany
    {
        return $this->belongsToMany(ScenicSpot::class, 'resource_provider_scenic_spots');
    }

    /**
     * 生成唯一的资源方编码
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = 'RP' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        } while (static::where('code', $code)->exists());
        
        return $code;
    }
}

{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'contact_name',
        'contact_phone',
        'contact_email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 关联的用户（运营人员）
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_resource_providers');
    }

    /**
     * 关联的景区
     */
    public function scenicSpots(): BelongsToMany
    {
        return $this->belongsToMany(ScenicSpot::class, 'resource_provider_scenic_spots');
    }

    /**
     * 生成唯一的资源方编码
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = 'RP' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        } while (static::where('code', $code)->exists());
        
        return $code;
    }
}

{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'contact_name',
        'contact_phone',
        'contact_email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 关联的用户（运营人员）
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_resource_providers');
    }

    /**
     * 关联的景区
     */
    public function scenicSpots(): BelongsToMany
    {
        return $this->belongsToMany(ScenicSpot::class, 'resource_provider_scenic_spots');
    }

    /**
     * 生成唯一的资源方编码
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = 'RP' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        } while (static::where('code', $code)->exists());
        
        return $code;
    }
}