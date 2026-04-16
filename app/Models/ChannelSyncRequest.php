<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChannelSyncRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'source',
        'request_id',
        'payload_hash',
        'status',
        'result_summary',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'result_summary' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
