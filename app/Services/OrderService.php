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
        // 验证状态流转是否合法
        if (!$this->canTransition($order->status, $newStatus)) {
            throw new \InvalidArgumentException("订单状态不能从 {$order->status->label()} 转换为 {$newStatus->label()}");
        }

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
     * 检查订单状态是否可以流转
     */
    protected function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        return match($from) {
            OrderStatus::PAID_PENDING => in_array($to, [
                OrderStatus::CONFIRMING,
                OrderStatus::REJECTED,
                OrderStatus::CANCEL_APPROVED, // 允许预下单取消
                OrderStatus::CANCEL_REQUESTED, // 允许取消申请
            ]),
            OrderStatus::CONFIRMING => in_array($to, [
                OrderStatus::CONFIRMED,
                OrderStatus::REJECTED,
                OrderStatus::CANCEL_APPROVED, // 允许美团主动退款时直接取消（接单失败场景）
            ]),
            OrderStatus::CONFIRMED => in_array($to, [
                OrderStatus::CANCEL_REQUESTED,
                OrderStatus::VERIFIED,
            ]),
            OrderStatus::CANCEL_REQUESTED => in_array($to, [
                OrderStatus::CANCEL_APPROVED,
                OrderStatus::CANCEL_REJECTED,
            ]),
            OrderStatus::CANCEL_REJECTED => in_array($to, [
                OrderStatus::CONFIRMED, // 取消被拒绝，回到确认状态
            ]),
            // 终止状态不能再流转
            OrderStatus::CANCEL_APPROVED,
            OrderStatus::VERIFIED,
            OrderStatus::REJECTED => false,
        };
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
