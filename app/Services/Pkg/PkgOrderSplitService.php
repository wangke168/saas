<?php

namespace App\Services\Pkg;

use App\Models\Pkg\PkgOrder;
use App\Models\Pkg\PkgOrderItem;
use App\Enums\PkgOrderItemStatus;
use App\Enums\PkgOrderItemType;
use App\Jobs\Pkg\ProcessSplitOrderJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PkgOrderSplitService
{
    /**
     * 拆单并处理（创建订单项后异步处理）
     */
    public function splitAndProcess(PkgOrder $order): array
    {
        DB::beginTransaction();
        try {
            // 1. 先创建订单项
            $result = $this->splitOrder($order);
            
            if (!$result['success']) {
                DB::rollBack();
                return $result;
            }
            
            DB::commit();
            
            // 2. 异步处理拆分后的订单项（调用第三方API等）
            ProcessSplitOrderJob::dispatch($order->id);
            
            Log::info('PkgOrderSplitService: 订单拆分成功，已提交处理任务', [
                'order_id' => $order->id,
                'order_items_count' => count($result['order_items'] ?? []),
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PkgOrderSplitService: 订单拆分失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 拆单并创建订单项（内部方法）
     */
    public function splitOrder(PkgOrder $order): array
    {
        DB::beginTransaction();
        try {
            // 1. 加载产品清单
            $product = $order->product;
            $bundleItems = $product->bundleItems;
            
            // 2. 创建子订单记录
            $orderItems = [];
            
            // 2.1 创建门票订单项（必选）
            foreach ($bundleItems as $item) {
                $ticket = $item->ticket;
                $orderItem = PkgOrderItem::create([
                    'order_id' => $order->id,
                    'item_type' => PkgOrderItemType::TICKET,
                    'resource_id' => $item->ticket_id,
                    'resource_name' => $ticket->name ?? '',
                    'quantity' => $item->quantity,
                    'status' => PkgOrderItemStatus::PENDING,
                ]);
                $orderItems[] = $orderItem;
            }
            
            // 2.2 创建酒店订单项
            $roomType = $order->roomType;
            $orderItem = PkgOrderItem::create([
                'order_id' => $order->id,
                'item_type' => PkgOrderItemType::HOTEL,
                'resource_id' => $order->room_type_id,
                'resource_name' => $roomType->name ?? '',
                'quantity' => 1,
                'status' => PkgOrderItemStatus::PENDING,
            ]);
            $orderItems[] = $orderItem;
            
            DB::commit();
            
            return ['success' => true, 'order_items' => $orderItems];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('拆单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
