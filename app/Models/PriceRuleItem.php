<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceRuleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'price_rule_id',
        'hotel_id',
        'room_type_id',
    ];

    /**
     * 所属加价规则
     */
    public function priceRule(): BelongsTo
    {
        return $this->belongsTo(PriceRule::class);
    }

    /**
     * 所属酒店
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * 所属房型
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
