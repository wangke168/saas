<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFliggyMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'hotel_id',
        'room_type_id',
        'scenic_spot_id',
        'fliggy_product_id',
        'is_active',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 关联产品
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * 关联酒店
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * 关联房型
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * 关联景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }
}

