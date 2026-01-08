<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $oldStatus = $order->status;

        DB::transaction(function () use ($order, $oldStatus, $newStatus, $remark, $operatorId) {
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

        // 刷新订单对象，确保获取最新状态
        $order->refresh();

        // 在事务提交后触发钉钉通知（避免阻塞事务）
        $this->triggerStatusChangeNotifications($order, $oldStatus, $newStatus, $remark);
    }

    /**
     * 触发状态变更通知
     */
    protected function triggerStatusChangeNotifications(Order $order, OrderStatus $oldStatus, OrderStatus $newStatus, ?string $remark = null): void
    {
        try {
            // 1. 订单进入确认中状态时通知
            if ($newStatus === OrderStatus::CONFIRMING && $oldStatus !== OrderStatus::CONFIRMING) {
                Log::info('OrderService: 触发订单确认中状态通知', [
                    'order_id' => $order->id,
                    'old_status' => $oldStatus->value,
                    'new_status' => $newStatus->value,
                ]);
                \App\Jobs\NotifyOrderConfirmingJob::dispatch($order);
            }

            // 2. 订单取消申请时通知 - 已移至OTA请求接收处触发，此处不再触发避免重复
            // 注意：取消申请通知现在在CtripController::handleCancelOrder和MeituanController::handleOrderRefund中触发

            // 3. 订单取消确认时通知（从取消申请中变为取消通过或拒绝）
            if (in_array($newStatus, [OrderStatus::CANCEL_APPROVED, OrderStatus::CANCEL_REJECTED]) 
                && $oldStatus === OrderStatus::CANCEL_REQUESTED) {
                Log::info('OrderService: 触发订单取消确认通知', [
                    'order_id' => $order->id,
                    'old_status' => $oldStatus->value,
                    'new_status' => $newStatus->value,
                ]);
                
                \App\Jobs\NotifyOrderCancelConfirmedJob::dispatch($order, $remark ?? '');
            }
        } catch (\Exception $e) {
            // 通知失败不影响订单状态更新，只记录日志
            Log::error('OrderService: 触发状态变更通知失败', [
                'order_id' => $order->id,
                'old_status' => $oldStatus->value,
                'new_status' => $newStatus->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
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
