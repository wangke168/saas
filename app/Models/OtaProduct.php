<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtaProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'ota_platform_id',
        'ota_product_id',
        'is_active',
        'pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pushed_at' => 'datetime',
        ];
    }

    /**
     * 产品
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * OTA平台
     */
    public function otaPlatform(): BelongsTo
    {
        return $this->belongsTo(OtaPlatform::class);
    }
}
