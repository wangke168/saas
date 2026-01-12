<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_product_id',
        'ticket_product_id',
        'hotel_product_id',
        'hotel_id',
        'room_type_id',
        'resource_service_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 关联的打包产品（Product）
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'package_product_id');
    }

    /**
     * 门票产品
     */
    public function ticketProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ticket_product_id');
    }

    /**
     * 酒店产品
     */
    public function hotelProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'hotel_product_id');
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
}


