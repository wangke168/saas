<?php

namespace App\Services\Presale;

use App\Enums\OrderEntitlementStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderBooking;
use App\Models\OrderEntitlement;
use App\Models\OrderLog;
use App\Services\OTA\NotificationFactory;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 预售父单 OTA 核销/查单：与 order_entitlements.booked 对齐（部分核销、累计份数、幂等）。
 */
final class PresaleOtaConsumeService
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public static function isPresaleParentOrder(Order $order): bool
    {
        if (PresaleFulfillmentOrderService::isFulfillmentChild($order)) {
            return false;
        }

        $order->loadMissing('product');

        return PresaleOrderService::isDeferredProduct($order->product);
    }

    public static function countBookedEntitlements(Order $presaleParent): int
    {
        return (int) OrderEntitlement::query()
            ->where('order_id', $presaleParent->id)
            ->where('status', OrderEntitlementStatus::Booked)
            ->count();
    }

    /** 预售父单总份数：以权益行数为准，兼容 room_count 与权益数不一致的历史数据 */
    public static function resolveTotalQuantity(Order $presaleParent): int
    {
        $entitlementCount = (int) OrderEntitlement::query()
            ->where('order_id', $presaleParent->id)
            ->count();

        return max(1, $entitlementCount, (int) $presaleParent->room_count);
    }

    /**
     * 预售履约子单景区接单成功后：按份通知 OTA 核销（累计已 booked 份数），幂等按权益行。
     */
    public function consumeAfterFulfillment(Order $fulfillmentChild): void
    {
        if (! PresaleFulfillmentOrderService::isFulfillmentChild($fulfillmentChild)) {
            return;
        }

        $parent = Order::query()
            ->with(['otaPlatform', 'product'])
            ->find($fulfillmentChild->parent_order_id);

        if ($parent === null || $parent->otaPlatform === null) {
            Log::warning('PresaleOtaConsumeService: 父单或 OTA 平台缺失，跳过核销', [
                'fulfillment_order_id' => $fulfillmentChild->id,
                'parent_order_id' => $fulfillmentChild->parent_order_id,
            ]);

            return;
        }

        if (! self::isPresaleParentOrder($parent)) {
            return;
        }

        $entitlement = $this->resolveEntitlementForFulfillment($fulfillmentChild);
        if ($entitlement === null) {
            Log::warning('PresaleOtaConsumeService: 未找到履约子单对应权益', [
                'fulfillment_order_id' => $fulfillmentChild->id,
                'parent_order_id' => $parent->id,
            ]);

            return;
        }

        if ($entitlement->ota_consumed_at !== null) {
            Log::info('PresaleOtaConsumeService: 权益已 OTA 核销，幂等跳过', [
                'entitlement_id' => $entitlement->id,
                'ota_consumed_at' => $entitlement->ota_consumed_at->toDateTimeString(),
            ]);
            $this->syncParentStatusFromBookedCount($parent);

            return;
        }

        $bookedCount = self::countBookedEntitlements($parent);
        $total = self::resolveTotalQuantity($parent);
        $bookedCount = min($bookedCount, $total);

        $consumeData = [
            'useQuantity' => $bookedCount,
            'use_quantity' => $bookedCount,
            'use_start_date' => $fulfillmentChild->check_in_date->format('Y-m-d'),
            'use_end_date' => $fulfillmentChild->check_out_date->format('Y-m-d'),
        ];

        DB::transaction(function () use ($parent, $fulfillmentChild, $entitlement, $consumeData, $bookedCount, $total): void {
            $locked = OrderEntitlement::query()
                ->where('id', $entitlement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->ota_consumed_at !== null) {
                return;
            }

            $notification = NotificationFactory::create($parent);
            if ($notification === null) {
                throw new \RuntimeException('无法创建 OTA 通知服务');
            }

            $notification->notifyOrderConsumed($parent, $consumeData);

            $locked->ota_consumed_at = now();
            $locked->save();

            $this->syncParentStatusFromBookedCount($parent, $bookedCount, $total, $fulfillmentChild->id);

            Log::info('PresaleOtaConsumeService: 预售 OTA 核销成功', [
                'parent_order_id' => $parent->id,
                'fulfillment_order_id' => $fulfillmentChild->id,
                'entitlement_id' => $locked->id,
                'booked_count' => $bookedCount,
                'total' => $total,
            ]);
        });
    }

    /**
     * 携程 QueryOrder：已用份数与状态码（7=部分使用，8=全部使用）。
     *
     * @return array{use_quantity: int, order_status: int}
     */
    public function resolveCtripQueryPayload(Order $presaleParent): array
    {
        $bookedCount = self::countBookedEntitlements($presaleParent);
        $total = self::resolveTotalQuantity($presaleParent);
        $useQuantity = min($bookedCount, $total);

        if ($useQuantity >= $total) {
            $orderStatus = 8;
        } elseif ($useQuantity > 0) {
            $orderStatus = 7;
        } else {
            $orderStatus = $this->mapBaseCtripStatus($presaleParent);
        }

        return [
            'use_quantity' => $useQuantity,
            'order_status' => $orderStatus,
        ];
    }

    protected function resolveEntitlementForFulfillment(Order $fulfillmentChild): ?OrderEntitlement
    {
        $booking = OrderBooking::query()
            ->where('fulfilled_order_id', $fulfillmentChild->id)
            ->first();

        if ($booking === null) {
            return null;
        }

        if ($booking->order_entitlement_id) {
            $entitlement = OrderEntitlement::query()->find($booking->order_entitlement_id);
            if ($entitlement !== null) {
                return $entitlement;
            }
        }

        return OrderEntitlement::query()
            ->where('order_booking_id', $booking->id)
            ->first();
    }

    protected function syncParentStatusFromBookedCount(
        Order $parent,
        ?int $bookedCount = null,
        ?int $total = null,
        ?int $fulfillmentOrderId = null,
    ): void {
        $parent->refresh();
        $bookedCount ??= self::countBookedEntitlements($parent);
        $total ??= self::resolveTotalQuantity($parent);
        $bookedCount = min($bookedCount, $total);

        $targetStatus = $bookedCount >= $total
            ? OrderStatus::VERIFIED
            : OrderStatus::CONFIRMED;

        $oldStatus = $parent->status;
        $remark = sprintf(
            '预售核销：累计 %d/%d 份（履约子单 %s）',
            $bookedCount,
            $total,
            $fulfillmentOrderId ?? '-',
        );

        if ($oldStatus !== $targetStatus) {
            try {
                $this->orderService->updateOrderStatus($parent, $targetStatus, $remark);
            } catch (\InvalidArgumentException $exception) {
                Log::warning('PresaleOtaConsumeService: 父单状态流转失败，仅记日志', [
                    'parent_order_id' => $parent->id,
                    'from' => $oldStatus->value,
                    'to' => $targetStatus->value,
                    'error' => $exception->getMessage(),
                ]);
                OrderLog::create([
                    'order_id' => $parent->id,
                    'from_status' => $oldStatus->value,
                    'to_status' => $targetStatus->value,
                    'remark' => $remark.'|status_update_failed',
                ]);
            }
        } else {
            OrderLog::create([
                'order_id' => $parent->id,
                'from_status' => $oldStatus->value,
                'to_status' => $oldStatus->value,
                'remark' => $remark,
            ]);
        }
    }

    protected function mapBaseCtripStatus(Order $order): int
    {
        if ($order->status === OrderStatus::CANCEL_APPROVED && $order->paid_at === null) {
            return 14;
        }

        return match ($order->status) {
            OrderStatus::PAID_PENDING => 11,
            OrderStatus::CONFIRMING => 12,
            OrderStatus::CONFIRMED => 2,
            OrderStatus::REJECTED => 1,
            OrderStatus::CANCEL_REQUESTED => 3,
            OrderStatus::CANCEL_APPROVED => 5,
            OrderStatus::VERIFIED => 8,
            default => 1,
        };
    }
}
