<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketPrice;
use Illuminate\Support\Facades\DB;

class TicketService
{
    /**
     * 创建门票
     */
    public function createTicket(array $data): Ticket
    {
        return DB::transaction(function () use ($data) {
            // 验证价格关系
            if (isset($data['market_price']) && isset($data['sale_price']) && isset($data['settlement_price'])) {
                $this->validatePriceRelationship(
                    $data['market_price'],
                    $data['sale_price'],
                    $data['settlement_price']
                );
            }
            
            $ticket = Ticket::create($data);
            
            return $ticket->load(['scenicSpot', 'softwareProvider']);
        });
    }

    /**
     * 更新门票
     */
    public function updateTicket(Ticket $ticket, array $data): Ticket
    {
        return DB::transaction(function () use ($ticket, $data) {
            // 验证价格关系
            $marketPrice = $data['market_price'] ?? $ticket->market_price;
            $salePrice = $data['sale_price'] ?? $ticket->sale_price;
            $settlementPrice = $data['settlement_price'] ?? $ticket->settlement_price;
            
            $this->validatePriceRelationship($marketPrice, $salePrice, $settlementPrice);
            
            // code 不可修改，如果传入则移除
            if (isset($data['code'])) {
                unset($data['code']);
            }
            
            $ticket->update($data);
            
            return $ticket->load(['scenicSpot', 'softwareProvider']);
        });
    }

    /**
     * 验证价格关系：门市价 >= 销售价 >= 结算价
     */
    public function validatePriceRelationship(float $marketPrice, float $salePrice, float $settlementPrice): void
    {
        if ($marketPrice < $salePrice) {
            throw new \Exception('销售价不能大于门市价');
        }
        
        if ($salePrice < $settlementPrice) {
            throw new \Exception('结算价不能大于销售价');
        }
        
        if ($marketPrice < 0 || $salePrice < 0 || $settlementPrice < 0) {
            throw new \Exception('价格不能小于0');
        }
    }

    /**
     * 获取指定日期的价格
     * 如果 ticket_prices 表中有该日期的记录，返回该记录的价格
     * 否则返回 tickets 表中的默认价格
     */
    public function getTicketPrice(Ticket $ticket, string $date): array
    {
        $ticketPrice = TicketPrice::where('ticket_id', $ticket->id)
            ->where('date', $date)
            ->first();
        
        if ($ticketPrice) {
            return [
                'market_price' => $ticketPrice->market_price,
                'sale_price' => $ticketPrice->sale_price,
                'settlement_price' => $ticketPrice->settlement_price,
                'is_custom' => true, // 标记为自定义价格
            ];
        }
        
        return [
            'market_price' => $ticket->market_price,
            'sale_price' => $ticket->sale_price,
            'settlement_price' => $ticket->settlement_price,
            'is_custom' => false, // 标记为默认价格
        ];
    }

    /**
     * 批量更新价格（按日期）
     * prices 格式：[{date: '2024-01-01', market_price: 100, sale_price: 80, settlement_price: 60}, ...]
     */
    public function batchUpdatePrices(Ticket $ticket, array $prices): array
    {
        return DB::transaction(function () use ($ticket, $prices) {
            $result = [];
            
            foreach ($prices as $priceData) {
                // 验证价格关系
                $this->validatePriceRelationship(
                    $priceData['market_price'],
                    $priceData['sale_price'],
                    $priceData['settlement_price']
                );
                
                // 使用 updateOrCreate 确保唯一性（ticket_id + date）
                $ticketPrice = TicketPrice::updateOrCreate(
                    [
                        'ticket_id' => $ticket->id,
                        'date' => $priceData['date'],
                    ],
                    [
                        'market_price' => $priceData['market_price'],
                        'sale_price' => $priceData['sale_price'],
                        'settlement_price' => $priceData['settlement_price'],
                    ]
                );
                
                $result[] = $ticketPrice;
            }
            
            return $result;
        });
    }

    /**
     * 删除门票（软删除）
     */
    public function deleteTicket(Ticket $ticket): bool
    {
        return DB::transaction(function () use ($ticket) {
            // 软删除门票，关联的价格也会被软删除（通过外键 CASCADE）
            return $ticket->delete();
        });
    }
}



