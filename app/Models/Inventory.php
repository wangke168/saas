<?php

namespace App\Models;

use App\Enums\PriceSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_type_id',
        'date',
        'total_quantity',
        'available_quantity',
        'locked_quantity',
        'source',
        'is_closed',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'source' => PriceSource::class,
            'is_closed' => 'boolean',
        ];
    }

    /**
     * 所属房型
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
