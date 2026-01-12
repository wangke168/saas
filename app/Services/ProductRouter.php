<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesProduct;
use Illuminate\Support\Facades\Log;

/**
 * 产品路由服务
 * 根据产品编码判断订单走哪个流程（Legacy 或 System_PKG）
 */
class ProductRouter
{
    /**
     * 路由判断：确定订单走哪个流程
     * 
     * @param string $productCode 产品编码
     * @return string 'LEGACY' | 'SYSTEM_PKG'
     */
    public function route(string $productCode): string
    {
        // 1. 优先检查 sales_products 表（新业务）
        $salesProduct = SalesProduct::where('ota_product_code', $productCode)->first();
        if ($salesProduct && $salesProduct->product_mode === 'SYSTEM_PKG') {
            Log::info('ProductRouter: 路由到 SYSTEM_PKG', [
                'product_code' => $productCode,
                'sales_product_id' => $salesProduct->id,
            ]);
            return 'SYSTEM_PKG';
        }
        
        // 2. 检查编码格式：PKG_ 开头 -> 新业务
        if (strpos($productCode, 'PKG_') === 0) {
            Log::info('ProductRouter: 通过编码格式判断为 SYSTEM_PKG', [
                'product_code' => $productCode,
            ]);
            return 'SYSTEM_PKG';
        }
        
        // 3. 检查编码格式：包含 | 分隔符 -> 旧业务
        if (strpos($productCode, '|') !== false) {
            Log::info('ProductRouter: 通过编码格式判断为 LEGACY', [
                'product_code' => $productCode,
            ]);
            return 'LEGACY';
        }
        
        // 4. 检查 products 表（旧业务）
        $product = Product::where('code', $productCode)->first();
        if ($product) {
            Log::info('ProductRouter: 通过 products 表判断为 LEGACY', [
                'product_code' => $productCode,
                'product_id' => $product->id,
            ]);
            return 'LEGACY';
        }
        
        // 5. 默认走旧流程（向后兼容）
        Log::warning('ProductRouter: 未找到产品，默认走 LEGACY 流程', [
            'product_code' => $productCode,
        ]);
        return 'LEGACY';
    }
}


