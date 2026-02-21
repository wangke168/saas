<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class ProductExternalCodeMapping extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'external_code',
        'start_date',
        'end_date',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * 序列化日期格式为 Y-m-d
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * 关联产品
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * 根据日期查找产品的外部编码
     * 
     * @param int $productId 产品ID
     * @param string|Carbon $date 日期（格式：Y-m-d 或 Carbon 对象）
     * @return string|null 外部编码，如果未找到返回 null
     */
    public static function findExternalCodeByDate(int $productId, $date): ?string
    {
        // 转换为字符串格式
        $dateString = $date instanceof Carbon ? $date->format('Y-m-d') : $date;
        
        // 查询包含该日期的有效映射
        $mapping = static::where('product_id', $productId)
            ->where('is_active', true)
            ->where('start_date', '<=', $dateString)
            ->where('end_date', '>=', $dateString)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
        
        return $mapping ? $mapping->external_code : null;
    }

    /**
     * 检查时间段是否与其他映射重叠
     * 
     * @param int $productId 产品ID
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @param int|null $excludeId 排除的映射ID（用于更新时排除自身）
     * @return bool true表示有重叠，false表示无重叠
     */
    public static function hasOverlap(int $productId, string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        $query = static::where('product_id', $productId)
            ->where('is_active', true)
            ->where(function ($q) use ($startDate, $endDate) {
                // 检查时间段重叠：新时间段与现有时间段有交集
                // 重叠条件：新开始日期 <= 现有结束日期 && 新结束日期 >= 现有开始日期
                // 即：现有开始日期 <= 新结束日期 && 现有结束日期 >= 新开始日期
                $q->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate);
            });
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->exists();
    }
}
