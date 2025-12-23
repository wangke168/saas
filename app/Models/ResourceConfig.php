<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_provider_id',
        'username',
        'password',
        'api_url',
        'environment',
        'extra_config',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'extra_config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 资源方
     */
    public function resourceProvider(): BelongsTo
    {
        return $this->belongsTo(ResourceProvider::class);
    }
}
