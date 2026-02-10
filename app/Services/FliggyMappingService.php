<?php

namespace App\Services;

use App\Models\ProductFliggyMapping;
use Illuminate\Support\Facades\Log;

class FliggyMappingService
{
    /**
     * 根据本地产品信息获取飞猪产品ID
     * 
     * @param int $productId 本地产品ID
     * @param int|null $hotelId 本地酒店ID
     * @param int|null $roomTypeId 本地房型ID
     * @return string|null 飞猪产品ID，如果不存在返回null
     */
    public function getFliggyProductId(int $productId, ?int $hotelId = null, ?int $roomTypeId = null): ?string
    {
        Log::info('FliggyMappingService: 开始验证映射关系', [
            'product_id' => $productId,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
        ]);
        
        $query = ProductFliggyMapping::where('product_id', $productId)
            ->where('is_active', true);
        
        if ($hotelId !== null) {
            $query->where('hotel_id', $hotelId);
        }
        
        if ($roomTypeId !== null) {
            $query->where('room_type_id', $roomTypeId);
        }
        
        $mapping = $query->first();
        
        if (!$mapping) {
            Log::warning('FliggyMappingService: 未找到映射关系', [
                'product_id' => $productId,
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
            ]);
            return null;
        }
        
        Log::info('FliggyMappingService: 映射关系验证成功', [
            'product_id' => $productId,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'fliggy_product_id' => $mapping->fliggy_product_id,
            'mapping_id' => $mapping->id,
        ]);
        
        return $mapping->fliggy_product_id;
    }
    
    /**
     * 检查映射是否存在
     * 
     * @param int $productId 本地产品ID
     * @param int|null $hotelId 本地酒店ID
     * @param int|null $roomTypeId 本地房型ID
     * @return bool
     */
    public function hasMapping(int $productId, ?int $hotelId = null, ?int $roomTypeId = null): bool
    {
        Log::info('FliggyMappingService: 检查映射关系是否存在', [
            'product_id' => $productId,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
        ]);
        
        $query = ProductFliggyMapping::where('product_id', $productId)
            ->where('is_active', true);
        
        if ($hotelId !== null) {
            $query->where('hotel_id', $hotelId);
        }
        
        if ($roomTypeId !== null) {
            $query->where('room_type_id', $roomTypeId);
        }
        
        $exists = $query->exists();
        
        Log::info('FliggyMappingService: 映射关系检查结果', [
            'product_id' => $productId,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'has_mapping' => $exists,
        ]);
        
        return $exists;
    }
    
    /**
     * 创建映射
     * 
     * @param array $data 映射数据
     * @return ProductFliggyMapping
     */
    public function createMapping(array $data): ProductFliggyMapping
    {
        $mapping = ProductFliggyMapping::create([
            'product_id' => $data['product_id'],
            'hotel_id' => $data['hotel_id'] ?? null,
            'room_type_id' => $data['room_type_id'] ?? null,
            'scenic_spot_id' => $data['scenic_spot_id'],
            'fliggy_product_id' => $data['fliggy_product_id'],
            'is_active' => $data['is_active'] ?? true,
            'remark' => $data['remark'] ?? null,
        ]);
        
        Log::info('FliggyMappingService: 创建映射成功', [
            'mapping_id' => $mapping->id,
            'product_id' => $mapping->product_id,
            'fliggy_product_id' => $mapping->fliggy_product_id,
        ]);
        
        return $mapping;
    }
    
    /**
     * 批量创建映射
     * 
     * @param array $mappings 映射数组
     * @return void
     */
    public function batchCreateMappings(array $mappings): void
    {
        $data = [];
        foreach ($mappings as $mapping) {
            $data[] = [
                'product_id' => $mapping['product_id'],
                'hotel_id' => $mapping['hotel_id'] ?? null,
                'room_type_id' => $mapping['room_type_id'] ?? null,
                'scenic_spot_id' => $mapping['scenic_spot_id'],
                'fliggy_product_id' => $mapping['fliggy_product_id'],
                'is_active' => $mapping['is_active'] ?? true,
                'remark' => $mapping['remark'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        
        ProductFliggyMapping::insert($data);
        
        Log::info('FliggyMappingService: 批量创建映射成功', [
            'count' => count($mappings),
        ]);
    }
}


