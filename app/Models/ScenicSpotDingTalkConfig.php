<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenicSpotDingTalkConfig extends Model
{
    use HasFactory;

    protected $table = 'scenic_spot_dingtalk_configs';

    protected $fillable = [
        'scenic_spot_id',
        'webhook_url',
        'enabled',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }
}

