<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PackageProduct;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PackageProductService
{
    public function __construct(
        protected ProductService $productService
    ) {}

    /**
     * 计算打包产品价格
     * 打包价格 = 门票价格 + 酒店价格
     * 
     * @param Product $packageProduct 打包产品
     * @param Hotel $hotel 酒店
     * @param RoomType $roomType 房型
     * @param string $date 日期
     * @return array ['market_price', 'settlement_price', 'sale_price']
     */
    public function calculatePackagePrice(
        Product $packageProduct,
        Hotel $hotel,
        RoomType $roomType,
        string $date
    ): array {
        $packageConfig = $packageProduct->packageProduct;
        if (!$packageConfig) {
            throw new \Exception('打包产品配置不存在');
        }

        // 获取门票价格（待实现门票价格逻辑）
        $ticketPrice = $this->getTicketPrice(
            $packageConfig->ticketProduct,
            $date
        );

        // 获取酒店价格
        $hotelPrice = $this->getHotelPrice(
            $packageConfig->hotelProduct,
            $roomType,
            $date
        );

        // 计算打包价格
        return [
            'market_price' => $ticketPrice['market_price'] + $hotelPrice['market_price'],
            'settlement_price' => $ticketPrice['settlement_price'] + $hotelPrice['settlement_price'],
            'sale_price' => $ticketPrice['sale_price'] + $hotelPrice['sale_price'],
        ];
    }

    /**
     * 获取门票价格
     * TODO: 实现门票价格逻辑
     */
    protected function getTicketPrice(Product $ticketProduct, string $date): array
    {
        // TODO: 实现门票价格获取逻辑
        // 门票可能按日期或固定价格
        // 目前返回0，待实现
        return [
            'market_price' => 0,
            'settlement_price' => 0,
            'sale_price' => 0,
        ];
    }

    /**
     * 获取酒店价格
     */
    protected function getHotelPrice(
        Product $hotelProduct,
        RoomType $roomType,
        string $date
    ): array {
        return $this->productService->calculatePrice($hotelProduct, $roomType->id, $date);
    }

    /**
     * 获取打包产品库存
     * 打包库存 = 酒店库存（门票通常不限制库存）
     * 
     * @param Product $packageProduct 打包产品
     * @param Hotel $hotel 酒店
     * @param RoomType $roomType 房型
     * @param string $date 日期
     * @return int 可用库存
     */
    public function getPackageInventory(
        Product $packageProduct,
        Hotel $hotel,
        RoomType $roomType,
        string $date
    ): int {
        // 获取酒店库存
        $inventory = Inventory::where('room_type_id', $roomType->id)
            ->where('date', $date)
            ->first();

        if (!$inventory || $inventory->is_closed) {
            return 0;
        }

        // 考虑stay_days（需要连续N天都有库存）
        $stayDays = $packageProduct->stay_days ?: 1;
        if ($stayDays > 1) {
            return $this->getContinuousInventory($roomType, $date, $stayDays);
        }

        return $inventory->available_quantity;
    }

    /**
     * 获取连续入住天数的库存
     * 
     * @param RoomType $roomType 房型
     * @param string $startDate 开始日期
     * @param int $stayDays 住几晚
     * @return int 可用库存（如果连续N天都有库存，返回最小库存；否则返回0）
     */
    protected function getContinuousInventory(RoomType $roomType, string $startDate, int $stayDays): int
    {
        $dateObj = \Carbon\Carbon::parse($startDate);
        $minInventory = null;

        // 检查从开始日期开始的连续N天
        for ($i = 0; $i < $stayDays; $i++) {
            $checkDate = $dateObj->copy()->addDays($i)->format('Y-m-d');
            
            $inventory = Inventory::where('room_type_id', $roomType->id)
                ->where('date', $checkDate)
                ->first();

            // 如果该日期没有库存记录，或关闭，或库存为0，返回0
            if (!$inventory || $inventory->is_closed || $inventory->available_quantity <= 0) {
                return 0;
            }

            // 记录最小库存
            if ($minInventory === null || $inventory->available_quantity < $minInventory) {
                $minInventory = $inventory->available_quantity;
            }
        }

        return $minInventory ?? 0;
    }

    /**
     * 创建打包产品
     */
    public function createPackageProduct(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            // 1. 创建打包产品（Product）
            $packageProduct = Product::create([
                'scenic_spot_id' => $data['scenic_spot_id'],
                'name' => $data['name'],
                'code' => $data['code'] ?? null, // 会自动生成
                'external_code' => $data['external_code'] ?? null,
                'description' => $data['description'] ?? null,
                'product_type' => 'package',
                'stay_days' => $data['stay_days'] ?? null, // 可配置，如果为NULL则使用访问器从酒店产品获取
                'is_active' => $data['is_active'] ?? true,
                'price_source' => null, // 打包产品不直接维护价格来源
            ]);

            // 2. 创建打包产品配置（PackageProduct）
            $packageConfig = PackageProduct::create([
                'package_product_id' => $packageProduct->id,
                'ticket_product_id' => $data['ticket_product_id'],
                'hotel_product_id' => $data['hotel_product_id'],
                'hotel_id' => $data['hotel_id'],
                'room_type_id' => $data['room_type_id'],
                'resource_service_type' => $data['resource_service_type'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $packageProduct->load(['packageProduct', 'scenicSpot']);
        });
    }

    /**
     * 更新打包产品
     */
    public function updatePackageProduct(Product $packageProduct, array $data): Product
    {
        return DB::transaction(function () use ($packageProduct, $data) {
            // 更新产品基本信息
            $packageProduct->update([
                'name' => $data['name'] ?? $packageProduct->name,
                'description' => $data['description'] ?? $packageProduct->description,
                'stay_days' => $data['stay_days'] ?? $packageProduct->stay_days,
                'is_active' => $data['is_active'] ?? $packageProduct->is_active,
            ]);

            // 更新打包产品配置
            if ($packageProduct->packageProduct) {
                $packageProduct->packageProduct->update([
                    'ticket_product_id' => $data['ticket_product_id'] ?? $packageProduct->packageProduct->ticket_product_id,
                    'hotel_product_id' => $data['hotel_product_id'] ?? $packageProduct->packageProduct->hotel_product_id,
                    'hotel_id' => $data['hotel_id'] ?? $packageProduct->packageProduct->hotel_id,
                    'room_type_id' => $data['room_type_id'] ?? $packageProduct->packageProduct->room_type_id,
                    'resource_service_type' => $data['resource_service_type'] ?? $packageProduct->packageProduct->resource_service_type,
                    'is_active' => $data['is_active'] ?? $packageProduct->packageProduct->is_active,
                ]);
            }

            return $packageProduct->load(['packageProduct', 'scenicSpot']);
        });
    }
}



