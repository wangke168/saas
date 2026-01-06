<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketPrice extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ticket_prices';

    protected $fillable = [
        'ticket_id',
        'date',
        'sale_price',
        'cost_price',
        'stock_available',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock_available' => 'integer',
        ];
    }

    /**
     * 所属门票
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
