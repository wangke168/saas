<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hotel extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'scenic_spot_id',
        'name',
        'code',
        'address',
        'contact_phone',
        'is_connected',
        'resource_provider_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_connected' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 所属景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    /**
     * 资源方
     */
    public function resourceProvider(): BelongsTo
    {
        return $this->belongsTo(ResourceProvider::class);
    }

    /**
     * 房型列表
     */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }
}
