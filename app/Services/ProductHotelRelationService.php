<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductHotelRelation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductHotelRelationService
{
    /**
     * 创建门票-酒店关联
     */
    public function createRelation(array $data): ProductHotelRelation
    {
        return DB::transaction(function () use ($data) {
            // 检查是否已存在
            $existing = ProductHotelRelation::where('ticket_product_id', $data['ticket_product_id'])
                ->where('hotel_product_id', $data['hotel_product_id'])
                ->where('hotel_id', $data['hotel_id'])
                ->where('room_type_id', $data['room_type_id'])
                ->first();

            if ($existing) {
                throw new \Exception('该关联已存在');
            }

            return ProductHotelRelation::create([
                'ticket_product_id' => $data['ticket_product_id'],
                'hotel_product_id' => $data['hotel_product_id'],
                'hotel_id' => $data['hotel_id'],
                'room_type_id' => $data['room_type_id'],
                'resource_service_type' => $data['resource_service_type'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
        });
    }

    /**
     * 根据门票产品获取关联的酒店产品列表
     */
    public function getHotelProductsByTicketProduct(Product $ticketProduct): \Illuminate\Database\Eloquent\Collection
    {
        return ProductHotelRelation::where('ticket_product_id', $ticketProduct->id)
            ->where('is_active', true)
            ->with(['hotelProduct', 'hotel', 'roomType'])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * 根据门票产品生成打包产品
     * 为每个关联的酒店产品创建一个打包产品
     */
    public function generatePackageProducts(Product $ticketProduct): array
    {
        $relations = $this->getHotelProductsByTicketProduct($ticketProduct);
        $createdPackages = [];

        foreach ($relations as $relation) {
            try {
                // 检查是否已存在打包产品
                $existingPackage = \App\Models\PackageProduct::where('ticket_product_id', $ticketProduct->id)
                    ->where('hotel_product_id', $relation->hotel_product_id)
                    ->where('hotel_id', $relation->hotel_id)
                    ->where('room_type_id', $relation->room_type_id)
                    ->first();

                if ($existingPackage) {
                    Log::info('打包产品已存在，跳过创建', [
                        'ticket_product_id' => $ticketProduct->id,
                        'hotel_product_id' => $relation->hotel_product_id,
                    ]);
                    continue;
                }

                // 创建打包产品
                $packageService = app(\App\Services\PackageProductService::class);
                $packageProduct = $packageService->createPackageProduct([
                    'scenic_spot_id' => $ticketProduct->scenic_spot_id,
                    'name' => $ticketProduct->name . ' + ' . $relation->hotelProduct->name . ' (' . $relation->roomType->name . ')',
                    'ticket_product_id' => $ticketProduct->id,
                    'hotel_product_id' => $relation->hotel_product_id,
                    'hotel_id' => $relation->hotel_id,
                    'room_type_id' => $relation->room_type_id,
                    'resource_service_type' => $relation->resource_service_type,
                    'stay_days' => $relation->hotelProduct->stay_days, // 默认使用酒店产品的stay_days
                    'is_active' => $relation->is_active,
                ]);

                $createdPackages[] = $packageProduct;
            } catch (\Exception $e) {
                Log::error('生成打包产品失败', [
                    'ticket_product_id' => $ticketProduct->id,
                    'hotel_product_id' => $relation->hotel_product_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $createdPackages;
    }
}


