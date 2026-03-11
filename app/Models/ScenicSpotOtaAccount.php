<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenicSpotOtaAccount extends Model
{
    use HasFactory;

    protected $table = 'scenic_spot_ota_accounts';

    protected $fillable = [
        'scenic_spot_id',
        'ota_platform_id',
        'account',
    ];

    /**
     * 景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    /**
     * OTA 平台
     */
    public function otaPlatform(): BelongsTo
    {
        return $this->belongsTo(OtaPlatform::class);
    }

    /**
     * 按景区+平台获取账号
     */
    public static function getAccountFor(?int $scenicSpotId, int $otaPlatformId): ?string
    {
        if ($scenicSpotId === null) {
            return null;
        }
        $row = static::where('scenic_spot_id', $scenicSpotId)
            ->where('ota_platform_id', $otaPlatformId)
            ->first();
        return $row?->account;
    }

    /**
     * 按平台+账号反查景区 ID（Webhook 路由用）
     */
    public static function getScenicSpotIdByAccount(int $otaPlatformId, string $account): ?int
    {
        $row = static::where('ota_platform_id', $otaPlatformId)
            ->where('account', $account)
            ->first();
        return $row?->scenic_spot_id;
    }
}
