<?php

namespace App\Models\Pkg;

use App\Models\OtaPlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PkgOtaProduct extends Model
{
    use HasFactory;

    protected $table = 'pkg_ota_products';

    protected $fillable = [
        'pkg_product_id',
        'ota_platform_id',
        'ota_product_id',
        'is_active',
        'pushed_at',
        'push_status',
        'push_started_at',
        'push_completed_at',
        'push_message',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pushed_at' => 'datetime',
            'push_started_at' => 'datetime',
            'push_completed_at' => 'datetime',
        ];
    }

    /**
     * 打包产品
     */
    public function pkgProduct(): BelongsTo
    {
        return $this->belongsTo(PkgProduct::class, 'pkg_product_id');
    }

    /**
     * OTA平台
     */
    public function otaPlatform(): BelongsTo
    {
        return $this->belongsTo(OtaPlatform::class, 'ota_platform_id');
    }
}


