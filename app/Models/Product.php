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
        'price_source',
        'is_active',
        'stay_days',
        'sale_start_date',
        'sale_end_date',
        'order_mode',
        'order_provider_id',
    ];

    protected function casts(): array
    {
        return [
            'price_source' => PriceSource::class,
            'is_active' => 'boolean',
            'sale_start_date' => 'date',
            'sale_end_date' => 'date',
        ];
    }

    /**
     * 序列化日期格式为 Y-m-d（与前端日期选择器的 value-format 一致）
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
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
}
