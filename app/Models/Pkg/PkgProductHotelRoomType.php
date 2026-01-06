<?php

namespace App\Models\Pkg;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PkgProductHotelRoomType extends Model
{
    use HasFactory;

    protected $table = 'pkg_product_hotel_room_types';

    protected $fillable = [
        'pkg_product_id',
        'hotel_id',
        'room_type_id',
    ];

    /**
     * 打包产品
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(PkgProduct::class, 'pkg_product_id');
    }

    /**
     * 酒店
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Res\ResHotel::class, 'hotel_id');
    }

    /**
     * 房型
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Res\ResRoomType::class, 'room_type_id');
    }
}
