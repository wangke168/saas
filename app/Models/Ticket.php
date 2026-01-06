<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tickets';

    /**
     * 模型启动方法
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($ticket) {
            if (empty($ticket->code)) {
                $ticket->code = static::generateUniqueCode();
            }
        });
    }

    /**
     * 生成唯一的门票编码（格式：T + 5位数字，如 T00001）
     */
    protected static function generateUniqueCode(): string
    {
        do {
            // 查找当前最大编号（以T开头，长度为6位）
            $maxCode = static::where('code', 'like', 'T%')
                ->whereRaw('LENGTH(code) = 6')
                ->orderByRaw('CAST(SUBSTRING(code, 2) AS UNSIGNED) DESC')
                ->value('code');
            
            if ($maxCode) {
                $nextNumber = intval(substr($maxCode, 1)) + 1;
            } else {
                $nextNumber = 1;
            }
            
            $code = 'T' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        } while (static::where('code', $code)->exists());
        
        return $code;
    }

    protected $fillable = [
        'scenic_spot_id',
        'software_provider_id',
        'name',
        'code',
        'external_ticket_id',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 所属景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class, 'scenic_spot_id');
    }

    /**
     * 软件服务商
     */
    public function softwareProvider(): BelongsTo
    {
        return $this->belongsTo(SoftwareProvider::class, 'software_provider_id');
    }

    /**
     * 价格列表
     */
    public function prices(): HasMany
    {
        return $this->hasMany(TicketPrice::class, 'ticket_id');
    }
}
