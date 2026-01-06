<?php

namespace App\Services;

use App\Models\ResHotelDailyStock;
use App\Models\ResRoomType;
use App\Models\SalesProduct;
use App\Models\SalesProductPrice;
use App\Models\Ticket;
use App\Models\TicketPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 系统打包产品价格计算服务
 * 价格公式：总价 = 酒店价格 + Σ(门票价格 × 1)
 */
class SystemPkgPriceService
{
    /**
     * 计算系统打包产品的价格
     * 
     * 价格公式：总价 = 酒店价格 + Σ(门票价格 × 1)
     * 
     * @param SalesProduct $salesProduct 销售产品
     * @param string $checkInDate 入住日期
     * @param int $stayDays 入住天数
     * @return array ['total_price' => 总价, 'breakdown' => 价格明细]
     */
    public function calculatePrice(SalesProduct $salesProduct, string $checkInDate, int $stayDays = 1): array
    {
        $totalPrice = 0;
        $breakdown = [];
        
        $bundleItems = $salesProduct->bundleItems;
        
        // 分组处理
        $ticketItems = $bundleItems->where('resource_type', 'TICKET');
        $hotelItems = $bundleItems->where('resource_type', 'HOTEL');
        
        // 1. 计算门票价格：门票价格 × 1
        foreach ($ticketItems as $item) {
            $ticket = Ticket::find($item->resource_id);
            if (!$ticket) {
                throw new \Exception("门票不存在：ID={$item->resource_id}");
            }
            
            // 获取门票价格（按使用日期）
            $ticketPrice = $this->getTicketPrice($ticket, $checkInDate);
            
            // 价格 = 门票单价 × 1 × 数量
            $itemTotal = $ticketPrice['sale_price'] * 1 * $item->quantity;
            $totalPrice += $itemTotal;
            
            $breakdown[] = [
                'type' => 'TICKET',
                'name' => $ticket->name,
                'quantity' => $item->quantity,
                'unit_price' => $ticketPrice['sale_price'],
                'multiplier' => 1,
                'total_price' => $itemTotal,
            ];
        }
        
        // 2. 计算酒店价格：酒店单价 × 数量 × 入住天数
        foreach ($hotelItems as $item) {
            $roomType = ResRoomType::find($item->resource_id);
            if (!$roomType) {
                throw new \Exception("房型不存在：ID={$item->resource_id}");
            }
            
            // 获取酒店价格（按入住日期）
            $hotelPrice = $this->getHotelPrice($roomType, $checkInDate, $stayDays);
            
            // 价格 = 酒店单价 × 数量 × 入住天数
            $itemTotal = $hotelPrice['sale_price'] * $item->quantity * $stayDays;
            $totalPrice += $itemTotal;
            
            $breakdown[] = [
                'type' => 'HOTEL',
                'name' => $roomType->name,
                'quantity' => $item->quantity,
                'stay_days' => $stayDays,
                'unit_price' => $hotelPrice['sale_price'],
                'total_price' => $itemTotal,
            ];
        }
        
        return [
            'total_price' => $totalPrice,
            'breakdown' => $breakdown,
        ];
    }
    
    /**
     * 获取门票价格（按日期）
     */
    private function getTicketPrice(Ticket $ticket, string $date): array
    {
        // 优先查 ticket_prices 表（特殊价格）
        $ticketPrice = TicketPrice::where('ticket_id', $ticket->id)
            ->where('date', $date)
            ->first();
        
        if ($ticketPrice) {
            return [
                'sale_price' => $ticketPrice->sale_price,
                'settlement_price' => $ticketPrice->settlement_price,
            ];
        }
        
        // 使用默认价格
        return [
            'sale_price' => $ticket->sale_price,
            'settlement_price' => $ticket->settlement_price,
        ];
    }
    
    /**
     * 获取酒店价格（按日期和入住天数）
     */
    private function getHotelPrice(ResRoomType $roomType, string $checkInDate, int $stayDays): array
    {
        $totalSalePrice = 0;
        $totalSettlementPrice = 0;
        
        $currentDate = Carbon::parse($checkInDate);
        for ($i = 0; $i < $stayDays; $i++) {
            $date = $currentDate->copy()->addDays($i)->format('Y-m-d');
            
            $dailyStock = ResHotelDailyStock::where('room_type_id', $roomType->id)
                ->where('biz_date', $date)
                ->first();
            
            if (!$dailyStock) {
                throw new \Exception("房型 {$roomType->name} 在日期 {$date} 没有价格库存数据");
            }
            
            $totalSalePrice += $dailyStock->sale_price;
            $totalSettlementPrice += $dailyStock->cost_price;
        }
        
        return [
            'sale_price' => $totalSalePrice,
            'settlement_price' => $totalSettlementPrice,
        ];
    }
    
    /**
     * 计算结算价
     */
    private function calculateSettlementPrice(SalesProduct $salesProduct, string $date): float
    {
        $totalSettlementPrice = 0;
        
        $bundleItems = $salesProduct->bundleItems;
        $ticketItems = $bundleItems->where('resource_type', 'TICKET');
        $hotelItems = $bundleItems->where('resource_type', 'HOTEL');
        
        // 门票结算价
        foreach ($ticketItems as $item) {
            $ticket = Ticket::find($item->resource_id);
            if ($ticket) {
                $ticketPrice = $this->getTicketPrice($ticket, $date);
                $totalSettlementPrice += $ticketPrice['settlement_price'] * 1 * $item->quantity;
            }
        }
        
        // 酒店结算价
        foreach ($hotelItems as $item) {
            $roomType = ResRoomType::find($item->resource_id);
            if ($roomType) {
                $hotelPrice = $this->getHotelPrice($roomType, $date, $salesProduct->stay_days);
                $totalSettlementPrice += $hotelPrice['settlement_price'] * $item->quantity * $salesProduct->stay_days;
            }
        }
        
        return $totalSettlementPrice;
    }
    
    /**
     * 实时更新价格日历（触发式服务）
     * 
     * 当门票价格或酒店价格变化时，自动更新所有相关产品的价格日历
     * 只更新未来60天
     */
    public function updatePriceCalendarOnChange(string $resourceType, int $resourceId): void
    {
        // 查找所有包含该资源的产品
        $salesProducts = SalesProduct::whereHas('bundleItems', function ($query) use ($resourceType, $resourceId) {
            $query->where('resource_type', $resourceType)
                  ->where('resource_id', $resourceId);
        })->get();
        
        foreach ($salesProducts as $product) {
            // 异步更新价格日历（只更新未来60天）
            \App\Jobs\UpdateSystemPkgPriceJob::dispatch($product->id);
        }
    }
    
    /**
     * 批量更新价格日历（队列任务调用）
     * 在销售日期范围内更新，最多60天
     */
    public function updatePriceCalendar(SalesProduct $salesProduct): void
    {
        $today = now();
        $saleStartDate = $salesProduct->sale_start_date;
        $saleEndDate = $salesProduct->sale_end_date;
        
        // 确定开始日期：取今天和销售开始日期的较大值
        $startDate = $today->greaterThan($saleStartDate) ? $today : $saleStartDate;
        
        // 确定结束日期：取销售结束日期和开始日期+60天的较小值
        $maxEndDate = $startDate->copy()->addDays(60);
        $endDate = $saleEndDate->lessThan($maxEndDate) ? $saleEndDate : $maxEndDate;
        
        // 确保不超过60天
        $calculatedDays = $startDate->diffInDays($endDate);
        if ($calculatedDays > 60) {
            $endDate = $startDate->copy()->addDays(60);
        }
        
        // 如果开始日期晚于结束日期，不更新
        if ($startDate->greaterThan($endDate)) {
            Log::info('SystemPkgPriceService: 销售日期范围无效，跳过价格更新', [
                'sales_product_id' => $salesProduct->id,
                'sale_start_date' => $salesProduct->sale_start_date->format('Y-m-d'),
                'sale_end_date' => $salesProduct->sale_end_date->format('Y-m-d'),
                'today' => $today->format('Y-m-d'),
            ]);
            return;
        }
        
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            $date = $currentDate->format('Y-m-d');
            $price = $this->calculatePrice($salesProduct, $date, $salesProduct->stay_days);
            
            // 更新或创建价格记录
            SalesProductPrice::updateOrCreate(
                [
                    'sales_product_id' => $salesProduct->id,
                    'date' => $date,
                ],
                [
                    'sale_price' => $price['total_price'],
                    'settlement_price' => $this->calculateSettlementPrice($salesProduct, $date),
                    'price_breakdown' => $price['breakdown'],
                ]
            );
            
            $currentDate->addDay();
        }
        
        Log::info('SystemPkgPriceService: 价格日历更新完成', [
            'sales_product_id' => $salesProduct->id,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'sale_start_date' => $saleStartDate->format('Y-m-d'),
            'sale_end_date' => $saleEndDate->format('Y-m-d'),
        ]);
    }
}

