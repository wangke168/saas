<?php

namespace App\Services\Pkg;

class PkgProductCodeService
{
    /**
     * 验证编码格式
     * 
     * 编码格式：PKG|RoomID|HotelID|ProductID
     */
    public static function validate(string $code): bool
    {
        if (!str_starts_with($code, 'PKG|')) {
            return false;
        }
        
        $parts = explode('|', $code);
        if (count($parts) !== 4) {
            return false;
        }
        
        // 验证各部分是否为数字
        for ($i = 1; $i < 4; $i++) {
            if (!is_numeric($parts[$i])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 解析编码
     * 
     * @param string $code 复合编码（PKG|RoomID|HotelID|ProductID）
     * @return array ['product_id' => int, 'hotel_id' => int, 'room_type_id' => int]
     * @throws \InvalidArgumentException
     */
    public static function parse(string $code): array
    {
        if (!self::validate($code)) {
            throw new \InvalidArgumentException("编码格式错误：{$code}");
        }
        
        $parts = explode('|', $code);
        return [
            'product_id' => (int)$parts[3],
            'hotel_id' => (int)$parts[2],
            'room_type_id' => (int)$parts[1],
        ];
    }
    
    /**
     * 生成复合编码
     * 
     * @param int $productId 产品ID
     * @param int $hotelId 酒店ID
     * @param int $roomTypeId 房型ID
     * @return string 格式：PKG|RoomID|HotelID|ProductID
     */
    public static function generate(int $productId, int $hotelId, int $roomTypeId): string
    {
        return "PKG|{$roomTypeId}|{$hotelId}|{$productId}";
    }
}
