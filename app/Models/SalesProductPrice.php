<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesProductPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_product_id',
        'date',
        'sale_price',
        'settlement_price',
        'price_breakdown',
        'stock_available',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sale_price' => 'decimal:2',
            'settlement_price' => 'decimal:2',
            'price_breakdown' => 'array',
            'stock_available' => 'integer',
        ];
    }

    /**
     * 所属销售产品
     */
    public function salesProduct(): BelongsTo
    {
        return $this->belongsTo(SalesProduct::class, 'sales_product_id');
    }
}




