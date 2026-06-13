<?php

namespace App\Models;

use App\Enums\PriceSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'fulfillment_mode' => 'immediate',
    ];

    /**
     * 模型启动方法
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($product) {
            // 验证软件服务商必填
            if (empty($product->software_provider_id)) {
                throw new \Exception('产品必须选择软件服务商');
            }
        });

        static::created(function ($product) {
            // 如果 code 为空，自动生成唯一编码（基于ID的36进制）
            if (empty($product->code)) {
                $product->code = static::generateUniqueCode($product->id);
                // 使用 saveQuietly 避免触发事件循环
                $product->saveQuietly();
            }
        });
    }

    /**
     * 生成唯一的产品编码（基于ID的36进制转换）
     * 格式：6位36进制编码（0-9, A-Z），左侧补0
     * 示例：ID=1 -> 000001, ID=100 -> 00002S, ID=1000 -> 000RS
     * 
     * @param int $id 产品ID
     * @return string 6位编码
     */
    public static function generateUniqueCode(int $id): string
    {
        // 将ID转换为36进制（0-9, A-Z）
        $code = strtoupper(base_convert($id, 10, 36));
        
        // 左侧补0至6位
        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    protected $fillable = [
        'scenic_spot_id',
        'software_provider_id',
        'name',
        'code',
        'external_code',
        'description',
        'cover_image',
        'booking_rules',
        'mp_content',
        'fee_note',
        'price_source',
        'is_active',
        'stay_days',
        'sale_start_date',
        'sale_end_date',
        'order_mode',
        'fulfillment_mode',
        'booking_advance_days',
        'order_provider_id',
        'is_realname',
        'id_region_restriction_enabled',
        'id_region_prefixes',
    ];

    protected function casts(): array
    {
        return [
            'price_source' => PriceSource::class,
            'fulfillment_mode' => 'string',
            'is_active' => 'boolean',
            'is_realname' => 'integer',
            'id_region_restriction_enabled' => 'boolean',
            'id_region_prefixes' => 'array',
            'sale_start_date' => 'date',
            'sale_end_date' => 'date',
            'booking_rules' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public function resolvedBookingRules(): array
    {
        $rules = $this->booking_rules;
        if (is_array($rules) && $rules !== []) {
            return array_values(array_filter(array_map(
                static fn (mixed $rule): string => trim((string) $rule),
                $rules,
            ), static fn (string $rule): bool => $rule !== ''));
        }

        return [
            '须在本小程序完成预约后方可入住',
            '预约成功后不可改期',
            '一份预售仅可预约一次',
            '入住人可与购买手机号不同',
        ];
    }

    public function resolvedFeeNote(): string
    {
        $note = trim((string) ($this->fee_note ?? ''));

        return $note !== '' ? $note : '已付金额为预售基础价；所选日期房型高于基础价需在线补差价。';
    }

    /**
     * 序列化日期格式为 Y-m-d（与前端日期选择器的 value-format 一致）
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * 预售预约最早入住日（相对今天）。immediate 产品恒为今天。
     */
    public function earliestCheckInDate(?\Carbon\Carbon $from = null): \Carbon\Carbon
    {
        $from = $from ?? \Carbon\Carbon::today();
        $days = 0;
        if ($this->fulfillment_mode === 'deferred') {
            $days = max(0, (int) ($this->booking_advance_days ?? 0));
        }

        return $from->copy()->addDays($days);
    }

    public function bookingAdvanceHint(): ?string
    {
        if ($this->fulfillment_mode !== 'deferred') {
            return null;
        }

        $days = max(0, (int) ($this->booking_advance_days ?? 0));
        if ($days <= 0) {
            return null;
        }

        return sprintf('须提前%d天预约，最早可选入住日为%s', $days, $this->earliestCheckInDate()->format('Y-m-d'));
    }

    /**
     * 所属景区
     */
    public function scenicSpot(): BelongsTo
    {
        return $this->belongsTo(ScenicSpot::class);
    }

    /**
     * 软件服务商（必填）
     */
    public function softwareProvider(): BelongsTo
    {
        return $this->belongsTo(SoftwareProvider::class);
    }

    /**
     * 订单下发服务商（可选，当order_mode为other时使用）
     */
    public function orderProvider(): BelongsTo
    {
        return $this->belongsTo(SoftwareProvider::class, 'order_provider_id');
    }

    /**
     * 价格列表
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /**
     * 加价规则列表
     */
    public function priceRules(): HasMany
    {
        return $this->hasMany(PriceRule::class);
    }

    /**
     * OTA产品关联
     */
    public function otaProducts(): HasMany
    {
        return $this->hasMany(OtaProduct::class);
    }

    /**
     * 订单列表
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * 外部编码时间段映射列表
     */
    public function externalCodeMappings(): HasMany
    {
        return $this->hasMany(ProductExternalCodeMapping::class);
    }

    /**
     * 不可订时段（房晚日期闭区间，与库存日历 date 含义一致）
     */
    public function unavailablePeriods(): HasMany
    {
        return $this->hasMany(ProductUnavailablePeriod::class)->orderBy('start_date');
    }
}
