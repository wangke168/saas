<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_no',
        'ota_order_no',
        'ctrip_item_id',
        'ota_platform_id',
        'product_id',
        'hotel_id',
        'room_type_id',
        'status',
        'check_in_date',
        'check_out_date',
        'room_count',
        'guest_count',
        'contact_name',
        'contact_phone',
        'contact_email',
        'card_no',
        'guest_info',
        'real_name_type',
        'credential_list',
        'total_amount',
        'settlement_amount',
        'resource_order_no',
        'remark',
        'paid_at',
        'confirmed_at',
        'cancelled_at',
        'refund_serial_no',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'guest_info' => 'array',
            'real_name_type' => 'integer',
            'credential_list' => 'array',
            'total_amount' => 'decimal:2',
            'settlement_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * OTA平台
     */
    public function otaPlatform(): BelongsTo
    {
        return $this->belongsTo(OtaPlatform::class);
    }

    /**
     * 产品（包括已软删除的，用于历史订单查询）
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * 酒店（包括已软删除的，用于历史订单查询）
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class)->withTrashed();
    }

    /**
     * 房型（包括已软删除的，用于历史订单查询）
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class)->withTrashed();
    }

    /**
     * 序列化日期格式为 Y-m-d（与前端日期选择器的 value-format 一致）
     * 确保 check_in_date 和 check_out_date 以正确的格式返回
     * 注意：这会影响所有日期字段的序列化格式，包括 datetime 类型字段
     * 前端 formatDateTime 函数使用 new Date() 可以正确解析 Y-m-d 格式的日期字符串
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * 获取系统服务商（通过酒店->景区关联）
     */
    public function getSoftwareProviderAttribute(): ?SoftwareProvider
    {
        return $this->hotel->scenicSpot->softwareProvider ?? null;
    }

    /**
     * 订单明细
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * 订单日志
     */
    public function logs(): HasMany
    {
        return $this->hasMany(OrderLog::class);
    }

    /**
     * 异常订单
     */
    public function exceptionOrder(): HasMany
    {
        return $this->hasMany(ExceptionOrder::class);
    }
}
