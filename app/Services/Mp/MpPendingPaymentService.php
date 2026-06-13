<?php

namespace App\Services\Mp;

use App\Enums\OrderBookingStatus;
use App\Enums\OrderEntitlementStatus;
use App\Models\OrderBooking;
use App\Models\OrderEntitlement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MpPendingPaymentService
{
    public function timeoutMinutes(): int
    {
        return max(1, (int) config('mp.payment_timeout_minutes', 10));
    }

    public function resolveExpiresAt(OrderBooking $booking): Carbon
    {
        if ($booking->payment_expires_at !== null) {
            return $booking->payment_expires_at instanceof Carbon
                ? $booking->payment_expires_at
                : Carbon::parse($booking->payment_expires_at);
        }

        return $booking->created_at->copy()->addMinutes($this->timeoutMinutes());
    }

    public function isExpired(OrderBooking $booking): bool
    {
        $status = $booking->status instanceof OrderBookingStatus
            ? $booking->status
            : OrderBookingStatus::from((string) $booking->status);

        if ($status !== OrderBookingStatus::PendingPayment) {
            return false;
        }

        return now()->greaterThanOrEqualTo($this->resolveExpiresAt($booking));
    }

    /**
     * @return array{payment_expires_at: string, payment_seconds_remaining: int, is_payment_expired: bool}
     */
    public function paymentCountdownPayload(OrderBooking $booking): array
    {
        $expiresAt = $this->resolveExpiresAt($booking);
        $remaining = max(0, $expiresAt->getTimestamp() - now()->getTimestamp());

        return [
            'payment_expires_at' => $expiresAt->toDateTimeString(),
            'payment_seconds_remaining' => $remaining,
            'is_payment_expired' => $remaining <= 0,
        ];
    }

    public function paymentExpiresAtForNewPendingOrder(): Carbon
    {
        return now()->addMinutes($this->timeoutMinutes());
    }

    /**
     * 超时则自动取消，返回是否已处理过期
     */
    public function expireIfOverdue(OrderBooking $booking): bool
    {
        if (!$this->isExpired($booking)) {
            return false;
        }

        DB::transaction(function () use ($booking): void {
            $lockedBooking = OrderBooking::query()
                ->where('id', $booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $status = $lockedBooking->status instanceof OrderBookingStatus
                ? $lockedBooking->status
                : OrderBookingStatus::from((string) $lockedBooking->status);

            if ($status !== OrderBookingStatus::PendingPayment) {
                return;
            }

            if (!$this->isExpired($lockedBooking)) {
                return;
            }

            $lockedBooking->update([
                'status' => OrderBookingStatus::Cancelled,
                'remark' => trim(($lockedBooking->remark ?? '').'|mp_payment_timeout:'.now()->toDateTimeString()),
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

        $booking->refresh();

        return true;
    }

    public function expireAllOverdue(): int
    {
        $cutoff = now()->subMinutes($this->timeoutMinutes());
        $count = 0;

        OrderBooking::query()
            ->where('status', OrderBookingStatus::PendingPayment)
            ->where(function ($query) use ($cutoff) {
                $query->where('payment_expires_at', '<=', now())
                    ->orWhere(function ($inner) use ($cutoff) {
                        $inner->whereNull('payment_expires_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$count) {
                foreach ($bookings as $booking) {
                    if ($this->expireIfOverdue($booking)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentActionFlags(OrderBooking $booking): array
    {
        $status = $booking->status instanceof OrderBookingStatus
            ? $booking->status
            : OrderBookingStatus::from((string) $booking->status);

        $isPendingPayment = $status === OrderBookingStatus::PendingPayment;
        $isPaid = in_array($status, [
            OrderBookingStatus::Paid,
            OrderBookingStatus::Fulfilling,
            OrderBookingStatus::Confirmed,
        ], true);

        $expired = $isPendingPayment && $this->isExpired($booking);

        $flags = [
            'can_cancel' => $isPendingPayment && !$expired,
            'can_continue_pay' => $isPendingPayment
                && !$expired
                && (float) $booking->surcharge_amount > 0,
            'is_paid' => $isPaid,
            'cancel_disabled_reason' => $isPaid ? '已支付订单不可取消' : ($expired ? '订单已超时自动取消' : null),
        ];

        if ($isPendingPayment) {
            return $flags + $this->paymentCountdownPayload($booking);
        }

        return $flags + [
            'payment_expires_at' => null,
            'payment_seconds_remaining' => 0,
            'is_payment_expired' => false,
        ];
    }
}
