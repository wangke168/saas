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

    protected $fillable = [
        'name',
        'code',
        'description',
        'address',
        'contact_phone',
        'software_provider_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 软件服务商
     */
    public function softwareProvider(): BelongsTo
    {
        return $this->belongsTo(SoftwareProvider::class);
    }

    /**
     * 绑定的用户
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
