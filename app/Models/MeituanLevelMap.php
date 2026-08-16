<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeituanLevelMap extends Model
{
    public const LEVEL_HOTEL = 1;

    public const LEVEL_ROOM = 2;

    protected $fillable = [
        'scenic_spot_id',
        'product_id',
        'level_id',
        'level_no',
        'hotel_id',
        'level_name',
    ];

    protected function casts(): array
    {
        return [
            'scenic_spot_id' => 'integer',
            'product_id' => 'integer',
            'level_id' => 'integer',
            'level_no' => 'integer',
            'hotel_id' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }
}
