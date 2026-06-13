<?php

namespace App\Services\Mp;

use App\Enums\OrderBookingStatus;
use App\Enums\OrderEntitlementStatus;
use App\Jobs\ProcessResourceOrderJob;
use App\Models\Order;
use App\Models\OrderBooking;
use App\Models\OrderEntitlement;
use App\Services\InventoryService;
use App\Services\Presale\PresaleFulfillmentOrderService;
use App\Services\Resource\ResourceServiceFactory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MpBookingFulfillmentService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly PresaleFulfillmentOrderService $fulfillmentOrderService,
    ) {}

    public function needsFulfillment(OrderBooking $booking): bool
    {
        $status = $booking->status instanceof OrderBookingStatus
            ? $booking->status
            : OrderBookingStatus::from((string) $booking->status);

        if ($status === OrderBookingStatus::Confirmed) {
            return false;
        }

        if ($status === OrderBookingStatus::Paid && ! empty($booking->payment_no)) {
            return false;
        }

        return in_array($status, [
            OrderBookingStatus::PendingPayment,
            OrderBookingStatus::Paid,
        ], true);
    }

    /**
     * 支付成功或零补差预约：锁库存、更新权益/父单、派发资源方履约。
     *
     * @throws \RuntimeException
     */
    public function fulfill(
        OrderBooking $booking,
        string $paymentNo,
        string $parentOrderStatusReason,
        string $bookingRemark = 'fulfilled_via_mp',
    ): void {
        $lockDates = [];
        $lockRoomTypeId = null;
        $inventoryLocked = false;
        $orderForResourceJob = null;

        try {
            DB::transaction(function () use (
                $booking,
                $paymentNo,
                $parentOrderStatusReason,
                $bookingRemark,
                &$lockDates,
                &$lockRoomTypeId,
                &$inventoryLocked,
                &$orderForResourceJob,
            ): void {
                $lockedBooking = OrderBooking::query()
                    ->where('id', $booking->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedBooking->status === OrderBookingStatus::Confirmed) {
                    if ($lockedBooking->fulfilled_order_id !== null) {
                        $existing = Order::query()->find($lockedBooking->fulfilled_order_id);
                        if ($existing !== null && PresaleFulfillmentOrderService::isFulfillmentChild($existing)) {
                            $orderForResourceJob = $existing;
                        }
                    }

                    return;
                }

                if ($lockedBooking->status === OrderBookingStatus::Paid && ! empty($lockedBooking->payment_no)) {
                    if ($lockedBooking->fulfilled_order_id !== null) {
                        $existing = Order::query()->find($lockedBooking->fulfilled_order_id);
                        if ($existing !== null && PresaleFulfillmentOrderService::isFulfillmentChild($existing)) {
                            $orderForResourceJob = $existing;
                        }
                    }

                    return;
                }

                $lockedBooking->loadMissing(['order', 'presaleProduct', 'entitlement']);
                $parentOrder = $lockedBooking->order;
                if ($parentOrder === null) {
                    throw new \RuntimeException('预约单缺少父订单');
                }

                $checkInDate = $lockedBooking->check_in_date?->format('Y-m-d');
                if (! $checkInDate) {
                    throw new \RuntimeException('预约单缺少入住日期');
                }

                $lockedBooking->loadMissing('presaleProduct');
                // 与小程序 quote/日历一致：按产品 stay_days 占连住各晚，勿用 diffInDays(入住,离店)（离店日为退房日，易算多一晚）
                $stayDays = max(1, (int) ($lockedBooking->presaleProduct?->stay_days ?? 1));
                $lockDates = $this->inventoryService->getDateRange($checkInDate, $stayDays);
                $lockRoomTypeId = (int) $lockedBooking->room_type_id;
                $checkOutDate = $lockedBooking->check_out_date?->format('Y-m-d')
                    ?? Carbon::parse($checkInDate)->addDays($stayDays)->format('Y-m-d');

                // 小程序履约走数据库行锁即可（与外层事务嵌套）；避免 Redis inventory_lock 与 OTA 抢锁误报失败
                $locked = $this->inventoryService->lockInventoryForDates($lockRoomTypeId, $lockDates, 1, false);
                if (! $locked) {
                    throw new \RuntimeException(sprintf(
                        '占房失败：无法锁定库存（房型 %d，入住 %s，连住 %d 晚：%s）。请确认各晚可用库存>0 且未关房。',
                        $lockRoomTypeId,
                        $checkInDate,
                        $stayDays,
                        implode(', ', $lockDates),
                    ));
                }
                $inventoryLocked = true;

                $lockedBooking->status = OrderBookingStatus::Paid;
                $lockedBooking->paid_at = now();
                $lockedBooking->payment_no = $paymentNo;
                $lockedBooking->payment_expires_at = null;
                $lockedBooking->save();

                $entitlement = OrderEntitlement::query()
                    ->where('order_booking_id', $lockedBooking->id)
                    ->lockForUpdate()
                    ->first();

                if ($entitlement !== null) {
                    $entitlement->status = OrderEntitlementStatus::Booked;
                    $entitlement->booked_at = now();
                    $entitlement->save();
                }

                $fulfillmentOrder = $this->fulfillmentOrderService->createFromBooking($lockedBooking, $parentOrder);

                $resourceService = ResourceServiceFactory::getService($fulfillmentOrder, 'order');
                if ($resourceService !== null) {
                    // Job 在事务提交后派发，避免 sync 队列下横店网络失败导致整单回滚并释放库存
                    $orderForResourceJob = $fulfillmentOrder->fresh();
                } else {
                    Log::info('小程序预约履约：order_mode=manual，等待人工履约', [
                        'booking_id' => $lockedBooking->id,
                        'parent_order_id' => $parentOrder->id,
                        'fulfillment_order_id' => $fulfillmentOrder->id,
                    ]);
                }

                $lockedBooking->status = OrderBookingStatus::Confirmed;
                $lockedBooking->fulfilled_order_id = $fulfillmentOrder->id;
                $lockedBooking->remark = $bookingRemark;
                $lockedBooking->save();

                Log::info('小程序预约履约成功', [
                    'booking_id' => $lockedBooking->id,
                    'parent_order_id' => $parentOrder->id,
                    'fulfillment_order_id' => $fulfillmentOrder->id,
                    'ota_order_no' => $fulfillmentOrder->ota_order_no,
                    'payment_no' => $paymentNo,
                    'check_in_date' => $checkInDate,
                    'check_out_date' => $checkOutDate,
                ]);
            });

            if ($orderForResourceJob instanceof Order) {
                try {
                    ProcessResourceOrderJob::dispatch($orderForResourceJob, 'confirm');
                    Log::info('小程序预约：已派发资源方接单任务（需队列 worker/Horizon 消费 resource-push）', [
                        'fulfillment_order_id' => $orderForResourceJob->id,
                        'booking_id' => $booking->id,
                        'queue' => 'resource-push',
                    ]);
                } catch (\Throwable $jobException) {
                    Log::error('小程序预约：资源方接单任务失败，预约本地已确认，请检查队列/网络后重试 Job', [
                        'order_id' => $orderForResourceJob->id,
                        'booking_id' => $booking->id,
                        'error' => $jobException->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            if ($inventoryLocked && $lockRoomTypeId !== null && $lockDates !== []) {
                $released = $this->inventoryService->releaseInventoryForDates($lockRoomTypeId, $lockDates, 1, false);
                Log::warning('小程序预约履约异常：执行库存补偿释放', [
                    'booking_id' => $booking->id,
                    'room_type_id' => $lockRoomTypeId,
                    'dates' => $lockDates,
                    'released' => $released,
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * 零补差占房失败后恢复权益为待预约，便于客人重新选择。
     */
    public function revertAfterFulfillmentFailure(OrderBooking $booking, string $reason): void
    {
        DB::transaction(function () use ($booking, $reason): void {
            $lockedBooking = OrderBooking::query()
                ->where('id', $booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $status = $lockedBooking->status instanceof OrderBookingStatus
                ? $lockedBooking->status
                : OrderBookingStatus::from((string) $lockedBooking->status);

            if ($status === OrderBookingStatus::Confirmed) {
                return;
            }

            $lockedBooking->update([
                'status' => OrderBookingStatus::Failed,
                'remark' => trim(($lockedBooking->remark ?? '').'|mp_fulfill_failed:'.$reason),
                'payment_expires_at' => null,
            ]);

            $entitlement = OrderEntitlement::query()
                ->where('order_booking_id', $lockedBooking->id)
                ->lockForUpdate()
                ->first();

            if ($entitlement !== null) {
                $entitlement->update([
                    'status' => OrderEntitlementStatus::Pending,
                    'order_booking_id' => null,
                    'booked_at' => null,
                ]);
            }
        });
    }
}
