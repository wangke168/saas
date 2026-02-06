<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PackageProduct;
use App\Enums\OrderStatus;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderSplitService
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    /**
     * 拆分打包订单
     * 将主订单拆分为门票子订单和酒店子订单
     */
    public function splitPackageOrder(Order $mainOrder): array
    {
        if (!$mainOrder->product->isPackage()) {
            throw new \Exception('订单不是打包订单');
        }

        $packageProduct = $mainOrder->product->packageProduct;
        if (!$packageProduct) {
            throw new \Exception('打包产品配置不存在');
        }

        DB::beginTransaction();
        try {
            // 1. 创建门票子订单
            $ticketOrder = $this->createTicketOrder($mainOrder, $packageProduct);

            // 2. 创建酒店子订单
            $hotelOrder = $this->createHotelOrder($mainOrder, $packageProduct);

            // 3. 关联订单
            $ticketOrder->update(['related_order_id' => $hotelOrder->id]);
            $hotelOrder->update(['related_order_id' => $ticketOrder->id]);

            // 4. 更新主订单状态
            $mainOrder->update([
                'order_type' => 'main',
            ]);

            DB::commit();

            Log::info('打包订单拆分成功', [
                'main_order_id' => $mainOrder->id,
                'ticket_order_id' => $ticketOrder->id,
                'hotel_order_id' => $hotelOrder->id,
            ]);

            return [
                'success' => true,
                'message' => '订单拆分成功',
                'data' => [
                    'main_order' => $mainOrder,
                    'ticket_order' => $ticketOrder,
                    'hotel_order' => $hotelOrder,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('打包订单拆分失败', [
                'main_order_id' => $mainOrder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '订单拆分失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 创建门票子订单
     */
    protected function createTicketOrder(Order $mainOrder, PackageProduct $packageProduct): Order
    {
        // 计算门票订单金额（可以从主订单金额中拆分，或重新计算）
        // 这里简化处理，使用主订单金额的一部分
        // TODO: 根据实际业务逻辑调整金额计算
        
        return Order::create([
            'order_no' => $this->generateOrderNo(),
            'parent_order_id' => $mainOrder->id,
            'order_type' => 'ticket',
            'product_id' => $packageProduct->ticket_product_id,
            'ticket_product_id' => $packageProduct->ticket_product_id,
            'ota_platform_id' => $mainOrder->ota_platform_id,
            'ota_order_no' => $mainOrder->ota_order_no . '-TICKET', // 添加后缀标识
            'status' => $mainOrder->status,
            'check_in_date' => $mainOrder->check_in_date,
            'check_out_date' => $mainOrder->check_in_date, // 门票通常是当天使用
            'room_count' => 1, // 门票通常按份数，这里简化处理
            'guest_count' => $mainOrder->guest_count,
            'contact_name' => $mainOrder->contact_name,
            'contact_phone' => $mainOrder->contact_phone,
            'contact_email' => $mainOrder->contact_email,
            'guest_info' => $mainOrder->guest_info,
            'real_name_type' => $mainOrder->real_name_type,
            'credential_list' => $mainOrder->credential_list,
            // TODO: 计算门票订单金额
            'total_amount' => 0,
            'settlement_amount' => 0,
            'paid_at' => $mainOrder->paid_at,
        ]);
    }

    /**
     * 创建酒店子订单
     */
    protected function createHotelOrder(Order $mainOrder, PackageProduct $packageProduct): Order
    {
        return Order::create([
            'order_no' => $this->generateOrderNo(),
            'parent_order_id' => $mainOrder->id,
            'order_type' => 'hotel',
            'product_id' => $packageProduct->hotel_product_id,
            'hotel_id' => $packageProduct->hotel_id,
            'room_type_id' => $packageProduct->room_type_id,
            'ota_platform_id' => $mainOrder->ota_platform_id,
            'ota_order_no' => $mainOrder->ota_order_no . '-HOTEL', // 添加后缀标识
            'status' => $mainOrder->status,
            'check_in_date' => $mainOrder->check_in_date,
            'check_out_date' => $mainOrder->check_out_date,
            'room_count' => $mainOrder->room_count,
            'guest_count' => $mainOrder->guest_count,
            'contact_name' => $mainOrder->contact_name,
            'contact_phone' => $mainOrder->contact_phone,
            'contact_email' => $mainOrder->contact_email,
            'guest_info' => $mainOrder->guest_info,
            'real_name_type' => $mainOrder->real_name_type,
            'credential_list' => $mainOrder->credential_list,
            // TODO: 计算酒店订单金额
            'total_amount' => $mainOrder->total_amount,
            'settlement_amount' => $mainOrder->settlement_amount,
            'paid_at' => $mainOrder->paid_at,
        ]);
    }

    /**
     * 同步订单状态
     * 当子订单状态变化时，同步到主订单
     */
    public function syncOrderStatus(Order $order): void
    {
        if (!$order->parent_order_id) {
            return; // 不是子订单，不需要同步
        }

        $parentOrder = $order->parentOrder;
        if (!$parentOrder) {
            return;
        }

        $childOrders = $parentOrder->childOrders;

        // 检查所有子订单状态
        $allConfirmed = $childOrders->every(function ($childOrder) {
            return $childOrder->status === OrderStatus::CONFIRMED;
        });

        if ($allConfirmed && $parentOrder->status !== OrderStatus::CONFIRMED) {
            $this->orderService->updateOrderStatus(
                $parentOrder,
                OrderStatus::CONFIRMED,
                '所有子订单已确认'
            );
        }
    }

    /**
     * 生成订单号
     */
    protected function generateOrderNo(): string
    {
        return 'ORD' . date('YmdHis') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }
}



