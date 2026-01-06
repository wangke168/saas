<?php

namespace App\Services\Pkg;

use App\Models\Pkg\PkgProduct;
use App\Models\Pkg\PkgProductDailyPrice;
use Illuminate\Support\Facades\Log;

class PkgOrderRouter
{
    /**
     * 路由订单（根据复合编码查找订单信息）
     * 
     * @param string $compositeCode 复合编码（PKG|RoomID|HotelID|ProductID）
     * @param string $date 业务日期（Y-m-d格式）
     * @return array ['product' => PkgProduct, 'hotel_id' => int, 'room_type_id' => int, 'price' => array]
     * @throws \Exception
     */
    public function route(string $compositeCode, string $date): array
    {
        // 验证编码格式
        if (!PkgProductCodeService::validate($compositeCode)) {
            throw new \Exception("编码格式错误：{$compositeCode}");
        }
        
        // 解析编码
        $parsed = PkgProductCodeService::parse($compositeCode);
        $productId = $parsed['product_id'];
        $hotelId = $parsed['hotel_id'];
        $roomTypeId = $parsed['room_type_id'];
        
        // 查找打包产品
        $product = PkgProduct::find($productId);
        if (!$product) {
            throw new \Exception("打包产品不存在：ID={$productId}");
        }
        
        // 从预计算价格表中查找价格（优先使用预计算价格，性能更好）
        $dailyPrice = PkgProductDailyPrice::where('pkg_product_id', $productId)
            ->where('hotel_id', $hotelId)
            ->where('room_type_id', $roomTypeId)
            ->where('biz_date', $date)
            ->first();
        
        if ($dailyPrice) {
            // 使用预计算价格
            return [
                'product' => $product,
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'price' => [
                    'sale_price' => $dailyPrice->sale_price,
                    'cost_price' => $dailyPrice->cost_price,
                ],
            ];
        }
        
        // 如果没有预计算价格，实时计算（降级处理）
        Log::warning('打包产品订单路由：未找到预计算价格，使用实时计算', [
            'composite_code' => $compositeCode,
            'product_id' => $productId,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'date' => $date,
        ]);
        
        $priceService = new PkgProductPriceService();
        $price = $priceService->calculatePrice($product, $hotelId, $roomTypeId, $date);
        
        return [
            'product' => $product,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'price' => $price,
        ];
    }
}
