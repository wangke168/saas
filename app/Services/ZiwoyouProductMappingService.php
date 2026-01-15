<?php

namespace App\Services;

use App\Models\ZiwoyouProductMapping;
use Illuminate\Support\Facades\Log;

class ZiwoyouProductMappingService
{
    /**
     * 根据SKU获取自我游产品ID
     * 
     * @param int $productId 本地产品ID
     * @param int $hotelId 本地酒店ID
     * @param int $roomTypeId 本地房型ID
     * @return string|null 自我游产品ID，如果不存在返回null
     */
    public function getZiwoyouProductId(int $productId, int $hotelId, int $roomTypeId): ?string
    {
        $mapping = ZiwoyouProductMapping::where('product_id', $productId)
            ->where('hotel_id', $hotelId)
            ->where('room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->first();
            
        if (!$mapping) {
            Log::warning('自我游产品映射：未找到映射关系', [
                'product_id' => $productId,
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
            ]);
            return null;
        }
        
        return $mapping->ziwoyou_product_id;
    }
    
    /**
     * 检查映射是否存在
     * 
     * @param int $productId 本地产品ID
     * @param int $hotelId 本地酒店ID
     * @param int $roomTypeId 本地房型ID
     * @return bool
     */
    public function hasMapping(int $productId, int $hotelId, int $roomTypeId): bool
    {
        return ZiwoyouProductMapping::where('product_id', $productId)
            ->where('hotel_id', $hotelId)
            ->where('room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->exists();
    }
    
    /**
     * 批量获取映射关系
     * 
     * @param array $skus SKU数组，格式：[['product_id' => 1, 'hotel_id' => 2, 'room_type_id' => 3], ...]
     * @return array 映射关系数组，格式：['product_id_hotel_id_room_type_id' => 'ziwoyou_product_id', ...]
     */
    public function getBatchMappings(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }
        
        $query = ZiwoyouProductMapping::where('is_active', true);
        
        $orConditions = [];
        foreach ($skus as $sku) {
            $orConditions[] = function ($query) use ($sku) {
                $query->where('product_id', $sku['product_id'])
                    ->where('hotel_id', $sku['hotel_id'])
                    ->where('room_type_id', $sku['room_type_id']);
            };
        }
        
        if (!empty($orConditions)) {
            $query->where(function ($query) use ($orConditions) {
                foreach ($orConditions as $condition) {
                    $query->orWhere($condition);
                }
            });
        }
        
        $mappings = $query->get();
        
        $result = [];
        foreach ($mappings as $mapping) {
            $key = "{$mapping->product_id}_{$mapping->hotel_id}_{$mapping->room_type_id}";
            $result[$key] = $mapping->ziwoyou_product_id;
        }
        
        return $result;
    }
}

