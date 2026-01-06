<?php

namespace App\Models\Pkg;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PkgProductBundleItem extends Model
{
    use HasFactory;

    protected $table = 'pkg_product_bundle_items';

    protected $fillable = [
        'pkg_product_id',
        'ticket_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * 打包产品
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(PkgProduct::class, 'pkg_product_id');
    }

    /**
     * 门票
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Ticket::class, 'ticket_id');
    }
}
