<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLog;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * 创建订单
     */
    public function createOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $order = Order::create(array_merge($data, [
                'order_no' => $this->generateOrderNo(),
                'status' => OrderStatus::PAID_PENDING,
            ]));

            // 创建订单明细
            if (isset($data['items'])) {
                foreach ($data['items'] as $item) {
                    $order->items()->create($item);
                }
            }

            // 记录订单日志
            $this->logOrderStatus($order, null, OrderStatus::PAID_PENDING, '订单创建');

            return $order->load(['product', 'hotel', 'roomType', 'items']);
        });
    }

    /**
     * 更新订单状态
     */
    public function updateOrderStatus(Order $order, OrderStatus $newStatus, ?string $remark = null, ?int $operatorId = null): void
    {
        DB::transaction(function () use ($order, $newStatus, $remark, $operatorId) {
            $oldStatus = $order->status;
            $order->update(['status' => $newStatus]);

            // 记录状态变更日志
            $this->logOrderStatus($order, $oldStatus, $newStatus, $remark, $operatorId);

            // 根据状态更新相关时间戳
            if ($newStatus === OrderStatus::CONFIRMED) {
                $order->update(['confirmed_at' => now()]);
            } elseif ($newStatus === OrderStatus::CANCEL_APPROVED) {
                $order->update(['cancelled_at' => now()]);
            }
        });
    }

    /**
     * 记录订单状态变更日志
     */
    protected function logOrderStatus(Order $order, ?OrderStatus $fromStatus, OrderStatus $toStatus, ?string $remark = null, ?int $operatorId = null): void
    {
        OrderLog::create([
            'order_id' => $order->id,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus->value,
            'remark' => $remark,
            'operator_id' => $operatorId,
        ]);
    }

    /**
     * 生成订单号
     */
    protected function generateOrderNo(): string
    {
        return 'ORD' . date('YmdHis') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }
}

