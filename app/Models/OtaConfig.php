<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtaConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'ota_platform_id',
        'account',
        'secret_key',
        'aes_key',
        'aes_iv',
        'rsa_private_key',
        'rsa_public_key',
        'api_url',
        'callback_url',
        'environment',
        'is_active',
    ];

    protected $hidden = [
        'secret_key',
        'aes_key',
        'aes_iv',
        'rsa_private_key',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * OTA平台
     */
    public function otaPlatform(): BelongsTo
    {
        return $this->belongsTo(OtaPlatform::class);
    }
}
