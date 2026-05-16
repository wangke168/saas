<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenicSpotOtaInventoryPushConfig extends Model
{
    use HasFactory;

    protected $table = 'scenic_spot_ota_inventory_push_configs';

    protected $fillable = [
        'scenic_spot_id',
        'ota_platform_id',
        'push_zero_threshold',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'push_zero_threshold' => 'integer',
        ];
    }

    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    public function otaPlatform(): BelongsTo
    {
        return $this->belongsTo(OtaPlatform::class);
    }
}
