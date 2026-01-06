<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_product_id',
        'resource_type',
        'resource_id',
        'quantity',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * 所属销售产品
     */
    public function salesProduct(): BelongsTo
    {
        return $this->belongsTo(SalesProduct::class, 'sales_product_id');
    }

    /**
     * 获取资源对象（门票或房型）
     */
    public function getResource()
    {
        if ($this->resource_type === 'TICKET') {
            return Ticket::find($this->resource_id);
        } elseif ($this->resource_type === 'HOTEL') {
            return ResRoomType::find($this->resource_id);
        }
        return null;
    }
}

