<?php

namespace App\Services;

use App\Enums\ExceptionOrderType;
use App\Enums\OrderStatus;
use App\Models\ExceptionOrder;
use App\Models\Inventory;
use App\Models\Order;
use App\Services\Resource\HengdianService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class OrderProcessorService
{
    public function __construct(
        protected OrderService $orderService,
        protected HengdianService $hengdianService,
        protected InventoryService $inventoryService
    ) {}

    /**
     * 处理订单（从已支付/待确认到确认中）
     */
    public function processOrder(Order $order): void
    {
        try {
            DB::beginTransaction();

            // 1. 锁定库存
            if (!$this->lockInventory($order)) {
                // 库存不足，不直接拒单，保留订单供人工处理
                $this->createExceptionOrder($order, ExceptionOrderType::INVENTORY_MISMATCH, '库存不足');
                DB::commit();
                return;
            }

            // 2. 更新订单状态为确认中
            $this->orderService->updateOrderStatus($order, OrderStatus::CONFIRMING, '开始处理订单');

            // 3. 调用资源方接口
            $result = $this->hengdianService->book($order);

            if ($result['success']) {
                // 预订成功
                $confirmNo = $result['data']->ConfirmNo ?? '';
                $order->update([
                    'status' => OrderStatus::CONFIRMED,
                    'resource_order_no' => $confirmNo,
                    'confirmed_at' => now(),
                ]);

                $this->orderService->updateOrderStatus($order, OrderStatus::CONFIRMED, '预订成功，确认号：' . $confirmNo);

                // 通知OTA平台订单确认
                \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
            } else {
                // 预订失败
                $this->orderService->updateOrderStatus($order, OrderStatus::REJECTED, '预订失败：' . ($result['message'] ?? ''));
                $this->createExceptionOrder($order, ExceptionOrderType::API_ERROR, $result['message'] ?? '预订失败');
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('订单处理异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, ExceptionOrderType::API_ERROR, $e->getMessage());
        }
    }

    /**
     * 锁定库存
     * 注意：如果订单来自预下单流程，库存已经在预下单时锁定，这里不需要重复锁定
     */
    protected function lockInventory(Order $order): bool
    {
        // 检查订单是否来自预下单流程
        // 如果订单的 paid_at 不为 null，说明是预下单支付后的订单，库存已经在预下单时锁定
        // 这里只需要验证库存是否已经锁定，不需要再次锁定
        if ($order->paid_at !== null) {
            // 预下单流程：检查库存是否已经锁定
            $inventory = Inventory::where('room_type_id', $order->room_type_id)
                ->where('date', $order->check_in_date)
                ->first();

            if (!$inventory) {
                Log::warning('预下单订单处理：库存记录不存在', [
                    'order_id' => $order->id,
                    'room_type_id' => $order->room_type_id,
                    'date' => $order->check_in_date,
                ]);
                return false;
            }

            // 检查锁定的库存是否足够（预下单时已经锁定）
            if ($inventory->locked_quantity >= $order->room_count) {
                Log::info('预下单订单处理：库存已锁定，跳过锁定步骤', [
                    'order_id' => $order->id,
                    'locked_quantity' => $inventory->locked_quantity,
                    'order_room_count' => $order->room_count,
                ]);
                return true; // 库存已经锁定，返回成功
            }

            // 如果 locked_quantity 不足，可能是异常情况，记录日志
            Log::warning('预下单订单处理：库存锁定数量异常', [
                'order_id' => $order->id,
                'locked_quantity' => $inventory->locked_quantity,
                'order_room_count' => $order->room_count,
            ]);
            return false;
        }

        // 非预下单流程：使用统一的库存服务锁定库存
        $stayDays = $order->product->stay_days ?? 1;
        $dates = $this->inventoryService->getDateRange($order->check_in_date, $stayDays);

        return $this->inventoryService->lockInventoryForDates(
            $order->room_type_id,
            $dates,
            $order->room_count
        );
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(Order $order, ExceptionOrderType $type, string $message): void
    {
        ExceptionOrder::create([
            'order_id' => $order->id,
            'exception_type' => $type,
            'exception_message' => $message,
            'status' => \App\Enums\ExceptionOrderStatus::PENDING,
        ]);
    }
}

