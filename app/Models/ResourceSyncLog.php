<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceSyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'software_provider_id',
        'scenic_spot_id',
        'sync_type',
        'sync_mode',
        'status',
        'message',
        'synced_count',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_count' => 'integer',
            'last_synced_at' => 'datetime',
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
}

    protected $fillable = [
        'software_provider_id',
        'scenic_spot_id',
        'sync_type',
        'sync_mode',
        'status',
        'message',
        'synced_count',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_count' => 'integer',
            'last_synced_at' => 'datetime',
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
}

    protected $fillable = [
        'software_provider_id',
        'scenic_spot_id',
        'sync_type',
        'sync_mode',
        'status',
        'message',
        'synced_count',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_count' => 'integer',
            'last_synced_at' => 'datetime',
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
}

    protected $fillable = [
        'software_provider_id',
        'scenic_spot_id',
        'sync_type',
        'sync_mode',
        'status',
        'message',
        'synced_count',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_count' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * 系统服务商
     */
    public function softwareProvider(): BelongsTo
    {
        return $this->belongsTo(SoftwareProvider::class);
    }