<?php

namespace App\Services\Pkg;

use App\Models\Pkg\PkgProduct;
use App\Models\Pkg\PkgProductDailyPrice;
use App\Models\Pkg\PkgProductHotelRoomType;
use App\Models\Res\ResHotelDailyStock;
use App\Models\TicketPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PkgProductPriceService
{
    /**
     * 预计算产品的未来60天价格
     */
    public function calculateDailyPrices(PkgProduct $product): void
    {
        try {
            DB::beginTransaction();

            // 获取产品的所有关联房型（只计算启用状态的房型）
            $hotelRoomTypes = $product->hotelRoomTypes()
                ->with(['roomType', 'hotel'])
                ->whereHas('roomType', function ($query) {
                    $query->where('is_active', true);
                })
                ->get();

            if ($hotelRoomTypes->isEmpty()) {
                Log::warning('打包产品没有关联的启用房型', ['product_id' => $product->id]);
                DB::rollBack();
                return;
            }

            // 获取产品的所有关联门票
            $bundleItems = $product->bundleItems()->with('ticket')->get();

            if ($bundleItems->isEmpty()) {
                Log::warning('打包产品没有关联的门票', ['product_id' => $product->id]);
                DB::rollBack();
                return;
            }

            // 获取有效的销售日期范围（考虑销售开始和结束日期）
            $dateRange = $product->getEffectiveSaleDateRange();
            
            if (!$dateRange['start'] || !$dateRange['end']) {
                Log::warning('打包产品销售日期范围无效，跳过价格计算', [
                    'product_id' => $product->id,
                    'sale_start_date' => $product->sale_start_date,
                    'sale_end_date' => $product->sale_end_date,
                ]);
                DB::rollBack();
                return;
            }

            $startDate = $dateRange['start'];
            $endDate = $dateRange['end'];
            $pricesToInsert = [];

            // 遍历每个房型组合
            foreach ($hotelRoomTypes as $hotelRoomType) {
                $roomType = $hotelRoomType->roomType;
                $hotel = $hotelRoomType->hotel;

                // 生成OTA编码：PKG|RoomID|HotelID|ProductID
                $compositeCode = $this->buildCompositeCode(
                    $roomType->code,
                    $hotel->code,
                    $product->product_code
                );

                // 遍历未来60天的每一天
                $currentDate = $startDate->copy();
                while ($currentDate->lte($endDate)) {
                    // 计算该日期的价格
                    $priceData = $this->calculateSinglePrice(
                        $product,
                        $hotelRoomType,
                        $bundleItems,
                        $currentDate
                    );

                    // 准备插入数据
                    $pricesToInsert[] = [
                        'pkg_product_id' => $product->id,
                        'hotel_id' => $hotelRoomType->hotel_id,
                        'room_type_id' => $hotelRoomType->room_type_id,
                        'biz_date' => $currentDate->format('Y-m-d'),
                        'sale_price' => $priceData['sale_price'],
                        'cost_price' => $priceData['cost_price'],
                        'composite_code' => $compositeCode,
                        'last_updated_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $currentDate->addDay();
                }
            }

            // 批量删除旧的价格数据（未来60天）
            PkgProductDailyPrice::where('pkg_product_id', $product->id)
                ->whereBetween('biz_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->delete();

            // 批量插入新价格（分批插入，避免单次插入数据过多）
            $chunks = array_chunk($pricesToInsert, 500);
            foreach ($chunks as $chunk) {
                PkgProductDailyPrice::insert($chunk);
            }

            DB::commit();

            Log::info('打包产品价格预计算完成', [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'price_count' => count($pricesToInsert),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('打包产品价格预计算失败', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 重新计算产品的价格（价格变更时触发）
     */
    public function recalculateDailyPrices(PkgProduct $product): void
    {
        $this->calculateDailyPrices($product);
    }

    /**
     * 批量预计算所有启用产品的价格
     */
    public function calculateAllProductsPrices(): void
    {
        $products = PkgProduct::where('status', 1)->get();

        foreach ($products as $product) {
            try {
                $this->calculateDailyPrices($product);
            } catch (\Exception $e) {
                Log::error('批量预计算价格失败', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
                // 继续处理下一个产品，不中断整个流程
            }
        }
    }

    /**
     * 计算单个产品-房型-日期的价格
     * 
     * 价格公式：酒店价格 + Σ(门票价格 × 张数)
     * 其中"张数"是pkg_product_bundle_items.quantity字段（创建打包产品时指定）
     * 
     * 注意：所有价格单位均为元（yuan）
     * - ResHotelDailyStock 的 sale_price 和 cost_price 存储为元
     * - TicketPrice 的 sale_price 和 cost_price 存储为元
     * - 返回的 sale_price 和 cost_price 也为元
     * 
     * @param PkgProduct $product 打包产品
     * @param PkgProductHotelRoomType $hotelRoomType 酒店房型关联
     * @param \Illuminate\Database\Eloquent\Collection $bundleItems 门票关联项
     * @param Carbon $date 日期
     * @return array ['sale_price' => float, 'cost_price' => float] 价格单位为元
     */
    protected function calculateSinglePrice(
        PkgProduct $product,
        PkgProductHotelRoomType $hotelRoomType,
        $bundleItems,
        Carbon $date
    ): array {
        $dateString = $date->format('Y-m-d');

        // 1. 获取酒店房型该日期的价格（单位：元）
        $hotelStock = ResHotelDailyStock::where('hotel_id', $hotelRoomType->hotel_id)
            ->where('room_type_id', $hotelRoomType->room_type_id)
            ->where('biz_date', $dateString)
            ->first();

        $hotelSalePrice = $hotelStock ? (float) $hotelStock->sale_price : 0;
        $hotelCostPrice = $hotelStock ? (float) $hotelStock->cost_price : 0;

        // 2. 计算所有关联门票的价格总和（门票价格 × 张数，单位：元）
        $ticketSalePriceTotal = 0;
        $ticketCostPriceTotal = 0;

        foreach ($bundleItems as $bundleItem) {
            $ticketPrice = TicketPrice::where('ticket_id', $bundleItem->ticket_id)
                ->where('date', $dateString)
                ->first();

            if ($ticketPrice) {
                $quantity = $bundleItem->quantity ?? 1; // 门票张数
                $ticketSalePriceTotal += (float) $ticketPrice->sale_price * $quantity;
                $ticketCostPriceTotal += (float) $ticketPrice->cost_price * $quantity;
            }
        }

        // 3. 总价格 = 酒店价格 + 门票总价（单位：元）
        $salePrice = $hotelSalePrice + $ticketSalePriceTotal;
        $costPrice = $hotelCostPrice + $ticketCostPriceTotal;

        return [
            'sale_price' => max(0, $salePrice), // 确保价格不为负数（单位：元）
            'cost_price' => max(0, $costPrice), // 单位：元
        ];
    }

    /**
     * 构建OTA编码
     * 格式：PKG|RoomID|HotelID|ProductID
     * 
     * @param string $roomTypeCode 房型编码
     * @param string $hotelCode 酒店编码
     * @param string $productCode 产品编码
     * @return string
     */
    protected function buildCompositeCode(string $roomTypeCode, string $hotelCode, string $productCode): string
    {
        return "PKG|{$roomTypeCode}|{$hotelCode}|{$productCode}";
    }
}
