<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtaProductSyncLog extends Model
{
    protected $fillable = [
        'product_id',
        'hotel_id',
        'room_type_id',
        'ota_platform_id',
        'last_price_hash',
        'last_stock_hash',
        'last_price_synced_at',
        'last_stock_synced_at',
        'last_price_data',
        'last_stock_data',
    ];

    protected function casts(): array
    {
        return [
            'last_price_synced_at' => 'datetime',
            'last_stock_synced_at' => 'datetime',
            'last_price_data' => 'array',
            'last_stock_data' => 'array',
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
     * 酒店
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * 房型
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * OTA平台
     */
    public function otaPlatform(): BelongsTo
    {
        return $this->belongsTo(OtaPlatform::class);
    }
}
