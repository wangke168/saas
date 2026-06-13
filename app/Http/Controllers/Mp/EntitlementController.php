<?php

namespace App\Http\Controllers\Mp;

use App\Enums\GuestIdType;
use App\Enums\OrderBookingStatus;
use App\Enums\OrderEntitlementStatus;
use App\Models\OrderBooking;
use App\Models\OrderEntitlement;
use App\Services\Mp\MpAuthService;
use App\Support\ProductMpPayload;
use App\Services\Mp\MpPendingPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntitlementController extends BaseMpController
{
    public function __construct(
        private readonly MpAuthService $mpAuthService,
        private readonly MpPendingPaymentService $pendingPaymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        $entitlements = OrderEntitlement::query()
            ->with(['order', 'product', 'booking'])
            ->whereHas('order', function ($query) use ($phone) {
                $query->where('contact_phone', $phone);
            })
            ->orderByDesc('id')
            ->get()
            ->map(function (OrderEntitlement $entitlement) {
                if ($entitlement->booking !== null) {
                    $this->pendingPaymentService->expireIfOverdue($entitlement->booking);
                    $entitlement->refresh();
                    $entitlement->load(['order', 'product', 'booking']);
                }

                return $this->formatEntitlementSummary($entitlement);
            })
            ->values();

        return response()->json([
            'message' => 'success',
            'phone' => $phone,
            'data' => $entitlements,
        ]);
    }

    public function show(Request $request, string $entitlement_no): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        $entitlement = OrderEntitlement::query()
            ->with(['order', 'product', 'booking.hotel', 'booking.roomType'])
            ->where('entitlement_no', $entitlement_no)
            ->whereHas('order', function ($query) use ($phone) {
                $query->where('contact_phone', $phone);
            })
            ->first();

        if ($entitlement === null) {
            return response()->json(['message' => '权益不存在'], 404);
        }

        if ($entitlement->booking !== null) {
            $this->pendingPaymentService->expireIfOverdue($entitlement->booking);
            $entitlement->refresh();
            $entitlement->load(['order', 'product', 'booking.hotel', 'booking.roomType']);
        }

        $product = $entitlement->product;

        return response()->json([
            'message' => 'success',
            'data' => [
                'entitlement' => $this->formatEntitlementSummary($entitlement),
                'product' => $product ? array_merge([
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'stay_days' => $product->stay_days,
                    'booking_advance_days' => max(0, (int) ($product->booking_advance_days ?? 0)),
                    'booking_advance_hint' => $product->bookingAdvanceHint(),
                    'sale_start_date' => $product->sale_start_date?->format('Y-m-d'),
                    'sale_end_date' => $product->sale_end_date?->format('Y-m-d'),
                ], ProductMpPayload::forMp($product)) : null,
                'booking' => $this->formatBookingDetail($entitlement->booking, $product?->stay_days),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntitlementSummary(OrderEntitlement $entitlement): array
    {
        $order = $entitlement->order;
        $status = $entitlement->status instanceof OrderEntitlementStatus
            ? $entitlement->status
            : OrderEntitlementStatus::from((string) $entitlement->status);

        return [
            'id' => $entitlement->id,
            'entitlement_no' => $entitlement->entitlement_no,
            'line_no' => $entitlement->line_no,
            'product_id' => $entitlement->product_id,
            'product_name' => $entitlement->product?->name,
            'status' => $status->value,
            'status_label' => $status->label(),
            'base_price' => (float) $entitlement->base_price,
            'ota_order_no' => $order?->ota_order_no,
            'booking_no' => $entitlement->booking?->booking_no,
            'check_in_date' => $entitlement->booking?->check_in_date?->format('Y-m-d'),
            'check_out_date' => $entitlement->booking?->check_out_date?->format('Y-m-d'),
            'booked_at' => $entitlement->booked_at?->toDateTimeString(),
            'booking_id' => $entitlement->booking?->id,
            'booking_status' => $entitlement->booking?->status instanceof OrderBookingStatus
                ? $entitlement->booking->status->value
                : ($entitlement->booking ? (string) $entitlement->booking->status : null),
            'payment_seconds_remaining' => $entitlement->booking
                ? $this->pendingPaymentService->paymentCountdownPayload($entitlement->booking)['payment_seconds_remaining']
                : 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatBookingDetail(?OrderBooking $booking, ?int $stayDays): ?array
    {
        if ($booking === null) {
            return null;
        }

        $status = $booking->status instanceof OrderBookingStatus
            ? $booking->status
            : OrderBookingStatus::from((string) $booking->status);

        $hotel = $booking->hotel;
        $roomType = $booking->roomType;
        $guestIdType = $booking->guest_id_type instanceof GuestIdType
            ? $booking->guest_id_type
            : GuestIdType::tryFrom((string) ($booking->guest_id_type ?? '')) ?? GuestIdType::IdCard;

        return [
            'id' => $booking->id,
            'booking_no' => $booking->booking_no,
            'status' => $status->value,
            'status_label' => $status->label(),
            'check_in_date' => $booking->check_in_date?->format('Y-m-d'),
            'check_out_date' => $booking->check_out_date?->format('Y-m-d'),
            'stay_days' => max(1, (int) ($stayDays ?: 1)),
            'hotel_id' => $booking->hotel_id,
            'hotel_name' => $hotel?->name,
            'hotel_address' => $hotel?->address,
            'hotel_contact_phone' => $hotel?->contact_phone,
            'room_type_id' => $booking->room_type_id,
            'room_type_name' => $roomType?->name,
            'guest_name' => $booking->guest_name,
            'guest_phone' => $booking->guest_phone,
            'guest_id_type' => $guestIdType->value,
            'guest_id_type_label' => $guestIdType->label(),
            'guest_id_number_label' => $guestIdType->numberLabel(),
            'guest_id_card' => $booking->guest_id_card,
            'package_sale_price' => (float) $booking->package_sale_price,
            'base_price' => (float) $booking->base_price,
            'surcharge_amount' => (float) $booking->surcharge_amount,
            'paid_at' => $booking->paid_at?->toDateTimeString(),
            'payment_no' => $booking->payment_no,
            ...$this->pendingPaymentService->paymentActionFlags($booking),
        ];
    }
}
