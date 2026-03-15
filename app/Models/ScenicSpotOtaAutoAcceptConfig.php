<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenicSpotOtaAutoAcceptConfig extends Model
{
    use HasFactory;

    protected $table = 'scenic_spot_ota_auto_accept_configs';

    protected $fillable = [
        'scenic_spot_id',
        'ota_platform_id',
        'auto_accept_when_sufficient',
        'auto_accept_stock_buffer',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'auto_accept_when_sufficient' => 'boolean',
            'is_active' => 'boolean',
            'auto_accept_stock_buffer' => 'integer',
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

