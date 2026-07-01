<?php

namespace App\Http\Controllers\Mp;

use App\Enums\GuestIdType;
use App\Enums\OrderBookingStatus;
use App\Enums\OrderEntitlementStatus;
use App\Models\Inventory;
use App\Models\Hotel;
use App\Models\OrderBooking;
use App\Models\OrderEntitlement;
use App\Models\Price;
use App\Models\ProductHotelRelation;
use App\Models\RoomType;
use App\Services\InventoryService;
use App\Services\Mp\MpAuthService;
use App\Services\Mp\MpBookingFulfillmentService;
use App\Services\Mp\MpPendingPaymentService;
use App\Services\Mp\MpWechatMiniService;
use App\Services\Mp\MpWechatPayService;
use App\Services\OrderService;
use App\Services\ProductService;
use App\Support\GuestDocumentValidator;
use App\Support\HotelMediaPayload;
use App\Support\ProductIdRegionRestriction;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookingController extends BaseMpController
{
    public function __construct(
        private readonly MpAuthService $mpAuthService,
        private readonly InventoryService $inventoryService,
        private readonly OrderService $orderService,
        private readonly ProductService $productService,
        private readonly MpPendingPaymentService $pendingPaymentService,
        private readonly MpBookingFulfillmentService $bookingFulfillmentService,
        private readonly MpWechatPayService $wechatPayService,
        private readonly MpWechatMiniService $wechatMiniService,
    ) {}

    public function calendar(Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'entitlement_no' => ['required', 'string'],
        ]);

        $entitlement = $this->findOwnedEntitlement($phone, (string) $validated['entitlement_no']);
        if ($entitlement === null) {
            return response()->json(['message' => '权益不存在'], 404);
        }

        $context = $this->buildEntitlementContext($entitlement);

        return response()->json([
            'message' => 'success',
            'data' => $this->buildCalendarAvailabilityRows($entitlement, $context),
            'meta' => [
                'booking_advance_days' => (int) ($context['booking_advance_days'] ?? 0),
                'earliest_check_in_date' => $context['earliest_check_in_date'] ?? null,
                'latest_check_in_date' => $context['latest_check_in_date'] ?? null,
                'sale_end_date' => $context['sale_end_date'] ?? null,
                'booking_advance_hint' => $context['booking_advance_hint'] ?? null,
            ],
        ]);
    }

    public function hotels(Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'entitlement_no' => ['required', 'string'],
            'check_in_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $entitlement = $this->findOwnedEntitlement($phone, (string) $validated['entitlement_no']);
        if ($entitlement === null) {
            return response()->json(['message' => '权益不存在'], 404);
        }

        $checkInDate = (string) $validated['check_in_date'];
        $context = $this->buildEntitlementContext($entitlement);
        if (!in_array($checkInDate, $context['candidate_dates'], true)) {
            return response()->json(['message' => '所选日期不在有效预约范围'], 422);
        }

        $dates = $this->inventoryService->getDateRange($checkInDate, $context['stay_days']);
        $roomTypeIds = Price::query()
            ->where('product_id', $entitlement->product_id)
            ->whereDate('date', $checkInDate)
            ->pluck('room_type_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($roomTypeIds)) {
            // 兜底：历史配置仍走 relation
            $roomTypeIds = ProductHotelRelation::query()
                ->where('ticket_product_id', $entitlement->product_id)
                ->where('is_active', true)
                ->pluck('room_type_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $inventoryMap = $this->loadInventoryMap($roomTypeIds, $dates);

        $rows = Hotel::query()
            ->whereHas('roomTypes', function ($query) use ($roomTypeIds) {
                $query->whereIn('room_types.id', $roomTypeIds);
            })
            ->with(['roomTypes' => function ($query) use ($roomTypeIds) {
                $query->whereIn('room_types.id', $roomTypeIds)->select('room_types.id', 'room_types.hotel_id');
            }])
            ->get()
            ->map(function (Hotel $hotel) use ($dates, $inventoryMap) {
                $hotelRoomTypeIds = $hotel->roomTypes
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                $hasInventory = $this->hasInventoryForStay($hotelRoomTypeIds, $dates, $inventoryMap);

                return [
                    'id' => $hotel->id,
                    'name' => $hotel->name,
                    'address' => $hotel->address,
                    'contact_phone' => $hotel->contact_phone,
                    'available' => $hasInventory,
                    ...HotelMediaPayload::forMpHotel($hotel),
                ];
            })
            ->values();

        return response()->json([
            'message' => 'success',
            'data' => $rows,
        ]);
    }

    public function roomTypes(Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'entitlement_no' => ['required', 'string'],
            'check_in_date' => ['required', 'date_format:Y-m-d'],
            'hotel_id' => ['required', 'integer', 'exists:hotels,id'],
        ]);

        $entitlement = $this->findOwnedEntitlement($phone, (string) $validated['entitlement_no']);
        if ($entitlement === null) {
            return response()->json(['message' => '权益不存在'], 404);
        }

        $checkInDate = (string) $validated['check_in_date'];
        $hotelId = (int) $validated['hotel_id'];
        $context = $this->buildEntitlementContext($entitlement);
        if (!in_array($checkInDate, $context['candidate_dates'], true)) {
            return response()->json(['message' => '所选日期不在有效预约范围'], 422);
        }

        $roomTypeIds = Price::query()
            ->where('product_id', $entitlement->product_id)
            ->whereDate('date', $checkInDate)
            ->whereHas('roomType', function ($query) use ($hotelId) {
                $query->where('hotel_id', $hotelId);
            })
            ->pluck('room_type_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($roomTypeIds)) {
            $roomTypeIds = ProductHotelRelation::query()
                ->where('ticket_product_id', $entitlement->product_id)
                ->where('hotel_id', $hotelId)
                ->where('is_active', true)
                ->pluck('room_type_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $context = $this->buildEntitlementContext($entitlement);

        $rows = RoomType::query()
            ->where('hotel_id', $hotelId)
            ->whereIn('id', $roomTypeIds)
            ->get()
            ->map(function (RoomType $roomType) use ($checkInDate, $entitlement, $hotelId, $context) {

            $quote = $this->buildQuote($entitlement, $hotelId, $roomType->id, $checkInDate, $context);
            if (!$quote['success']) {
                return [
                    'id' => $roomType->id,
                    'name' => $roomType->name,
                    'max_occupancy' => $roomType->max_occupancy,
                    'description_summary' => $roomType->description,
                    'stock' => 0,
                    'available' => false,
                    'package_sale_price' => 0,
                    'base_price' => (float) $entitlement->base_price,
                    'surcharge_hint' => '不可约',
                    'surcharge_amount' => 0,
                ];
            }

            $data = $quote['data'];

            return [
                'id' => $roomType->id,
                'name' => $roomType->name,
                'max_occupancy' => $roomType->max_occupancy,
                'description_summary' => $roomType->description,
                'stock' => (int) $data['stock_min'],
                'available' => true,
                'package_sale_price' => $data['package_sale_price'],
                'base_price' => $data['base_price'],
                'surcharge_hint' => $data['surcharge_amount'] > 0 ? '需补差' : '无需补差',
                'surcharge_amount' => $data['surcharge_amount'],
                ...HotelMediaPayload::forMpRoomType($roomType),
            ];
        })->values();

        return response()->json([
            'message' => 'success',
            'data' => $rows,
        ]);
    }

    public function hotelShow(Hotel $hotel, Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        return response()->json([
            'message' => 'success',
            'data' => [
                'id' => $hotel->id,
                'name' => $hotel->name,
                'address' => $hotel->address,
                'contact_phone' => $hotel->contact_phone,
                ...HotelMediaPayload::forMpHotel($hotel),
            ],
        ]);
    }

    public function roomTypeShow(RoomType $roomType, Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        return response()->json([
            'message' => 'success',
            'data' => [
                'id' => $roomType->id,
                'name' => $roomType->name,
                'max_occupancy' => $roomType->max_occupancy,
                'description' => $roomType->description,
                ...HotelMediaPayload::forMpRoomType($roomType),
            ],
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'entitlement_no' => ['required', 'string'],
            'hotel_id' => ['required', 'integer', 'exists:hotels,id'],
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'check_in_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $entitlement = $this->findOwnedEntitlement($phone, (string) $validated['entitlement_no']);
        if ($entitlement === null) {
            return response()->json(['message' => '权益不存在'], 404);
        }

        $quote = $this->buildQuote(
            $entitlement,
            (int) $validated['hotel_id'],
            (int) $validated['room_type_id'],
            (string) $validated['check_in_date'],
        );

        if (!$quote['success']) {
            return response()->json(['message' => $quote['message']], 422);
        }

        return response()->json([
            'message' => 'success',
            'data' => $quote['data'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'entitlement_no' => ['required', 'string'],
            'hotel_id' => ['required', 'integer', 'exists:hotels,id'],
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'check_in_date' => ['required', 'date_format:Y-m-d'],
            'guest_name' => ['required', 'string', 'max:50'],
            'guest_phone' => ['required', 'string', 'max:20'],
            'guest_id_type' => ['nullable', Rule::enum(GuestIdType::class)],
            'guest_id_card' => ['required', 'string', 'max:32'],
        ]);

        $guestIdType = isset($validated['guest_id_type'])
            ? ($validated['guest_id_type'] instanceof GuestIdType
                ? $validated['guest_id_type']
                : GuestIdType::from((string) $validated['guest_id_type']))
            : GuestIdType::IdCard;

        $guestError = GuestDocumentValidator::validationMessage(
            (string) $validated['guest_phone'],
            $guestIdType->value,
            (string) $validated['guest_id_card'],
        );
        if ($guestError !== null) {
            return response()->json(['message' => $guestError], 422);
        }

        $normalizedGuest = GuestDocumentValidator::normalize(
            (string) $validated['guest_phone'],
            $guestIdType->value,
            (string) $validated['guest_id_card'],
        );
        $validated['guest_phone'] = $normalizedGuest['guest_phone'];
        $validated['guest_id_card'] = $normalizedGuest['guest_id_card'];
        $validated['guest_id_type'] = $normalizedGuest['guest_id_type'];

        $entitlement = $this->findOwnedEntitlement($phone, (string) $validated['entitlement_no']);
        if ($entitlement === null) {
            return response()->json(['message' => '权益不存在'], 404);
        }

        $entitlement->loadMissing('product');
        $regionMessage = ProductIdRegionRestriction::validateMpGuest(
            $validated['guest_id_type'] instanceof GuestIdType
                ? $validated['guest_id_type']
                : GuestIdType::from((string) $validated['guest_id_type']),
            (string) $validated['guest_id_card'],
            $entitlement->product,
        );
        if ($regionMessage !== null) {
            return response()->json(['message' => $regionMessage], 422);
        }

        $quote = $this->buildQuote(
            $entitlement,
            (int) $validated['hotel_id'],
            (int) $validated['room_type_id'],
            (string) $validated['check_in_date'],
        );
        if (!$quote['success']) {
            return response()->json(['message' => $quote['message']], 422);
        }

        try {
            $booking = DB::transaction(function () use ($entitlement, $quote, $validated) {
                return $this->createOrResumeBooking($entitlement, $quote['data'], $validated);
            });
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ((float) $booking->surcharge_amount <= 0 && $this->bookingFulfillmentService->needsFulfillment($booking)) {
            try {
                $this->bookingFulfillmentService->fulfill(
                    $booking,
                    'MP_ZERO_'.now()->format('YmdHis'),
                    '小程序预约成功（零补差）',
                    'fulfilled_via_mp_zero',
                );
            } catch (\Throwable $exception) {
                $this->bookingFulfillmentService->revertAfterFulfillmentFailure($booking, $exception->getMessage());
                $booking->refresh();

                return response()->json(['message' => $exception->getMessage()], 422);
            }
            $booking->refresh();
        }

        $bookingStatus = $booking->status instanceof OrderBookingStatus
            ? $booking->status
            : OrderBookingStatus::from((string) $booking->status);

        return response()->json([
            'message' => 'success',
            'data' => [
                'booking_id' => $booking->id,
                'booking_no' => $booking->booking_no,
                'status' => $bookingStatus->value,
                'status_label' => $bookingStatus->label(),
                'surcharge_amount' => (float) $booking->surcharge_amount,
            ],
        ]);
    }

    public function cancel(OrderBooking $booking, Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        try {
            DB::transaction(function () use ($booking, $phone): void {
                $entitlement = OrderEntitlement::query()
                    ->where('order_booking_id', $booking->id)
                    ->whereHas('order', function ($query) use ($phone) {
                        $query->where('contact_phone', $phone);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($entitlement === null) {
                    throw new \DomainException('预约单不存在');
                }

                $lockedBooking = OrderBooking::query()
                    ->where('id', $booking->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $bookingStatus = $lockedBooking->status instanceof OrderBookingStatus
                    ? $lockedBooking->status
                    : OrderBookingStatus::from((string) $lockedBooking->status);

                if (in_array($bookingStatus, [
                    OrderBookingStatus::Paid,
                    OrderBookingStatus::Fulfilling,
                    OrderBookingStatus::Confirmed,
                ], true)) {
                    throw new \DomainException('已支付订单不可取消');
                }

                if ($bookingStatus !== OrderBookingStatus::PendingPayment) {
                    throw new \DomainException('仅待支付的预约可取消');
                }

                if ($this->pendingPaymentService->isExpired($lockedBooking)) {
                    $this->pendingPaymentService->expireIfOverdue($lockedBooking);
                    throw new \DomainException('订单已超时自动取消，请重新预约');
                }

                $lockedBooking->update([
                    'status' => OrderBookingStatus::Cancelled,
                    'remark' => trim(($lockedBooking->remark ?? '').'|mp_cancelled:'.now()->toDateTimeString()),
                ]);

                $entitlement->update([
                    'status' => OrderEntitlementStatus::Pending,
                    'order_booking_id' => null,
                    'booked_at' => null,
                ]);
            });
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => '预约已取消，可重新预约',
            'data' => [
                'entitlement_status' => OrderEntitlementStatus::Pending->value,
            ],
        ]);
    }

    public function pay(OrderBooking $booking, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login_code' => ['nullable', 'string', 'max:128'],
        ]);

        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        $owned = OrderEntitlement::query()
            ->where('order_booking_id', $booking->id)
            ->whereHas('order', function ($query) use ($phone) {
                $query->where('contact_phone', $phone);
            })
            ->exists();
        if (!$owned) {
            return response()->json(['message' => '预约单不存在'], 404);
        }

        if ($this->pendingPaymentService->expireIfOverdue($booking)) {
            return response()->json(['message' => '订单已超时自动取消，请重新预约'], 422);
        }

        $booking->refresh();
        $bookingStatus = $booking->status instanceof OrderBookingStatus
            ? $booking->status
            : OrderBookingStatus::from((string) $booking->status);

        if ($bookingStatus !== OrderBookingStatus::PendingPayment) {
            return response()->json(['message' => '当前状态不可支付'], 422);
        }

        if (! $this->wechatPayService->isConfigured()) {
            return response()->json([
                'message' => '微信支付未配置，请联系管理员或使用开发 mock 回调',
            ], 422);
        }

        try {
            $openid = $this->resolvePayOpenid($request, $validated['login_code'] ?? null);
            $payment = $this->wechatPayService->createMiniPayment($booking, $openid);
        } catch (\Throwable $exception) {
            Log::error('MpBooking pay: 微信下单失败', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
                'wechat_detail' => $exception instanceof \Yansongda\Artful\Exception\InvalidResponseException
                    ? $exception->response
                    : null,
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'success',
            'data' => [
                'booking_id' => $booking->id,
                'booking_no' => $booking->booking_no,
                'pay_status' => $bookingStatus->value,
                ...$this->pendingPaymentService->paymentCountdownPayload($booking),
                'payment' => $payment,
            ],
        ]);
    }

    public function show(OrderBooking $booking, Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        $owned = OrderEntitlement::query()
            ->where('order_booking_id', $booking->id)
            ->whereHas('order', function ($query) use ($phone) {
                $query->where('contact_phone', $phone);
            })
            ->exists();
        if (!$owned) {
            return response()->json(['message' => '预约单不存在'], 404);
        }

        $this->pendingPaymentService->expireIfOverdue($booking);
        $booking->refresh();
        $booking->loadMissing(['hotel', 'roomType', 'presaleProduct']);

        $bookingStatus = $booking->status instanceof OrderBookingStatus
            ? $booking->status
            : OrderBookingStatus::from((string) $booking->status);

        return response()->json([
            'message' => 'success',
            'data' => [
                'id' => $booking->id,
                'booking_no' => $booking->booking_no,
                'status' => $bookingStatus->value,
                'status_label' => $bookingStatus->label(),
                ...$this->pendingPaymentService->paymentActionFlags($booking),
                'check_in_date' => $booking->check_in_date?->format('Y-m-d'),
                'check_out_date' => $booking->check_out_date?->format('Y-m-d'),
                'stay_days' => max(1, (int) ($booking->presaleProduct?->stay_days ?: 1)),
                'hotel_id' => $booking->hotel_id,
                'hotel_name' => $booking->hotel?->name,
                'hotel_address' => $booking->hotel?->address,
                'room_type_id' => $booking->room_type_id,
                'room_type_name' => $booking->roomType?->name,
                'guest_name' => $booking->guest_name,
                'guest_phone' => $booking->guest_phone,
                ...$this->guestDocumentPayload($booking),
                'package_sale_price' => (float) $booking->package_sale_price,
                'base_price' => (float) $booking->base_price,
                'surcharge_amount' => (float) $booking->surcharge_amount,
                'paid_at' => $booking->paid_at?->toDateTimeString(),
                'payment_no' => $booking->payment_no,
            ],
        ]);
    }

    public function payCallback(OrderBooking $booking, Request $request): JsonResponse
    {
        // 预留给微信支付回调的落账入口，当前支持 mock_success 快速联调
        $validated = $request->validate([
            'mock_success' => ['nullable', 'boolean'],
            'payment_no' => ['nullable', 'string', 'max:64'],
        ]);

        if (! ($validated['mock_success'] ?? false)) {
            return response()->json([
                'message' => '请等待微信支付异步通知，或使用 mock_success=true 进行本地联调',
            ], 422);
        }

        if (! config('wechat.pay.allow_mock_callback')) {
            return response()->json([
                'message' => '当前环境已禁用 mock 支付回调',
            ], 422);
        }

        if ($this->pendingPaymentService->expireIfOverdue($booking)) {
            return response()->json(['message' => '订单已超时自动取消，请重新预约'], 422);
        }

        $booking->refresh();
        $currentStatus = $booking->status instanceof OrderBookingStatus
            ? $booking->status
            : OrderBookingStatus::from((string) $booking->status);
        if ($currentStatus === OrderBookingStatus::Confirmed) {
            return response()->json([
                'message' => 'success',
                'data' => [
                    'booking_id' => $booking->id,
                    'booking_no' => $booking->booking_no,
                    'status' => $currentStatus->value,
                ],
            ]);
        }

        try {
            $paymentNo = $validated['payment_no'] ?? ('MP_MOCK_'.now()->format('YmdHis'));
            $this->wechatPayService->fulfillPaidBooking($booking, $paymentNo);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'success',
            'data' => [
                'booking_id' => $booking->id,
                'booking_no' => $booking->booking_no,
                'status' => $booking->fresh()->status->value,
            ],
        ]);
    }

    private function findOwnedEntitlement(string $phone, string $entitlementNo): ?OrderEntitlement
    {
        return OrderEntitlement::query()
            ->with('order')
            ->where('entitlement_no', $entitlementNo)
            ->whereHas('order', function ($query) use ($phone) {
                $query->where('contact_phone', $phone);
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $quoteData
     * @param  array<string, mixed>  $validated
     */
    private function createOrResumeBooking(
        OrderEntitlement $entitlement,
        array $quoteData,
        array $validated,
    ): OrderBooking {
        $locked = OrderEntitlement::query()
            ->where('id', $entitlement->id)
            ->lockForUpdate()
            ->firstOrFail();

        $entitlementStatus = $this->normalizeEntitlementStatus($locked);

        if ($entitlementStatus === OrderEntitlementStatus::Booked) {
            throw new \DomainException('该权益已完成预约');
        }

        if ($entitlementStatus === OrderEntitlementStatus::Cancelled) {
            throw new \DomainException('该权益已取消');
        }

        if ($entitlementStatus === OrderEntitlementStatus::Booking) {
            $existing = OrderBooking::query()
                ->where('order_entitlement_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existingStatus = $existing->status instanceof OrderBookingStatus
                    ? $existing->status
                    : OrderBookingStatus::from((string) $existing->status);

                if ($existingStatus === OrderBookingStatus::PendingPayment) {
                    $this->pendingPaymentService->expireIfOverdue($existing);
                    $existing->refresh();
                    $existingStatus = $existing->status instanceof OrderBookingStatus
                        ? $existing->status
                        : OrderBookingStatus::from((string) $existing->status);
                    if ($existingStatus === OrderBookingStatus::PendingPayment) {
                        return $this->syncPendingBooking($existing, $locked, $quoteData, $validated);
                    }
                }

                if (in_array($existingStatus, [
                    OrderBookingStatus::Paid,
                    OrderBookingStatus::Fulfilling,
                    OrderBookingStatus::Confirmed,
                ], true)) {
                    if (
                        $existingStatus === OrderBookingStatus::Paid
                        && empty($existing->payment_no)
                        && (float) $existing->surcharge_amount <= 0
                    ) {
                        return $this->syncPendingBooking($existing, $locked, $quoteData, $validated);
                    }

                    throw new \DomainException('预约正在确认中，请勿重复提交');
                }
            }

            // 异常残留：权益为预约中但无有效预约单，恢复为待预约
            $locked->update([
                'status' => OrderEntitlementStatus::Pending,
                'order_booking_id' => null,
                'booked_at' => null,
            ]);
            $entitlementStatus = OrderEntitlementStatus::Pending;
        }

        if ($entitlementStatus !== OrderEntitlementStatus::Pending) {
            throw new \DomainException('权益状态不允许预约');
        }

        $requiresPayment = (float) $quoteData['surcharge_amount'] > 0;
        $bookingStatus = $requiresPayment
            ? OrderBookingStatus::PendingPayment
            : OrderBookingStatus::Paid;

        $booking = OrderBooking::create([
            'booking_no' => 'B'.now()->format('YmdHis').str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'order_entitlement_id' => $locked->id,
            'order_id' => $locked->order_id,
            'presale_product_id' => $locked->product_id,
            'package_product_id' => $locked->product_id,
            'hotel_id' => $quoteData['hotel_id'],
            'room_type_id' => $quoteData['room_type_id'],
            'check_in_date' => $quoteData['check_in_date'],
            'check_out_date' => $quoteData['check_out_date'],
            'guest_name' => $validated['guest_name'],
            'guest_phone' => $validated['guest_phone'],
            'guest_id_type' => $validated['guest_id_type'] instanceof GuestIdType
                ? $validated['guest_id_type']
                : GuestIdType::from((string) $validated['guest_id_type']),
            'guest_id_card' => $validated['guest_id_card'],
            'package_sale_price' => $quoteData['package_sale_price'],
            'base_price' => $quoteData['base_price'],
            'surcharge_amount' => $quoteData['surcharge_amount'],
            'status' => $bookingStatus,
            'paid_at' => null,
            'payment_expires_at' => $requiresPayment
                ? $this->pendingPaymentService->paymentExpiresAtForNewPendingOrder()
                : null,
            'remark' => $requiresPayment ? 'mp_booking' : 'mp_booking_zero_pending_fulfill',
        ]);

        $locked->status = OrderEntitlementStatus::Booking;
        $locked->order_booking_id = $booking->id;
        $locked->booked_at = null;
        $locked->save();

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $quoteData
     * @param  array<string, mixed>  $validated
     */
    private function syncPendingBooking(
        OrderBooking $booking,
        OrderEntitlement $entitlement,
        array $quoteData,
        array $validated,
    ): OrderBooking {
        $booking->update([
            'hotel_id' => $quoteData['hotel_id'],
            'room_type_id' => $quoteData['room_type_id'],
            'check_in_date' => $quoteData['check_in_date'],
            'check_out_date' => $quoteData['check_out_date'],
            'guest_name' => $validated['guest_name'],
            'guest_phone' => $validated['guest_phone'],
            'guest_id_type' => $validated['guest_id_type'] instanceof GuestIdType
                ? $validated['guest_id_type']
                : GuestIdType::from((string) $validated['guest_id_type']),
            'guest_id_card' => $validated['guest_id_card'],
            'package_sale_price' => $quoteData['package_sale_price'],
            'base_price' => $quoteData['base_price'],
            'surcharge_amount' => $quoteData['surcharge_amount'],
            'remark' => 'mp_booking_resume',
        ]);

        $entitlement->update([
            'status' => OrderEntitlementStatus::Booking,
            'order_booking_id' => $booking->id,
        ]);

        return $booking->fresh();
    }

    private function normalizeEntitlementStatus(OrderEntitlement $entitlement): OrderEntitlementStatus
    {
        if ($entitlement->status instanceof OrderEntitlementStatus) {
            return $entitlement->status;
        }

        return OrderEntitlementStatus::from((string) $entitlement->status);
    }

    /**
     * @param  array{stay_days:int,candidate_dates:array<int,string>,room_type_ids:array<int,int>,booking_advance_days:int,earliest_check_in_date:string,booking_advance_hint:?string}|null  $context
     */
    private function buildQuote(OrderEntitlement $entitlement, ?int $hotelId, int $roomTypeId, string $checkInDate, ?array $context = null): array
    {
        $context ??= $this->buildEntitlementContext($entitlement);
        if (!in_array($checkInDate, $context['candidate_dates'], true)) {
            $advanceDays = (int) ($context['booking_advance_days'] ?? 0);
            $earliest = $context['earliest_check_in_date'] ?? null;
            if ($advanceDays > 0 && is_string($earliest) && $earliest !== '' && $checkInDate < $earliest) {
                return [
                    'success' => false,
                    'message' => sprintf('须提前%d天预约，最早可选入住日为%s', $advanceDays, $earliest),
                ];
            }

            return ['success' => false, 'message' => '所选日期不在有效预约范围'];
        }

        $relationQuery = ProductHotelRelation::query()
            ->where('ticket_product_id', $entitlement->product_id)
            ->where('room_type_id', $roomTypeId)
            ->where('is_active', true);
        if ($hotelId !== null) {
            $relationQuery->where('hotel_id', $hotelId);
        }
        $relation = $relationQuery->first();

        if ($relation === null) {
            // 优先依据价格管理配置作为可约范围
            $priceScopeExists = Price::query()
                ->where('product_id', $entitlement->product_id)
                ->where('room_type_id', $roomTypeId)
                ->exists();
            if (!$priceScopeExists) {
                return ['success' => false, 'message' => '该酒店房型不在可预约范围'];
            }
        }

        $stayDays = $context['stay_days'];
        // 连住占房日期：入住日含起共 stay_days 晚（库存按携程口径逐晚校验）
        $occupancyDates = $this->inventoryService->getDateRange($checkInDate, $stayDays);

        $stockMin = PHP_INT_MAX;
        foreach ($occupancyDates as $date) {
            $inventory = Inventory::query()
                ->where('room_type_id', $roomTypeId)
                ->whereDate('date', $date)
                ->first();
            if ($inventory === null || $inventory->is_closed || $inventory->available_quantity <= 0) {
                return ['success' => false, 'message' => '库存不足'];
            }
            $stockMin = min($stockMin, (int) $inventory->available_quantity);
        }

        $entitlement->loadMissing('product');
        $product = $entitlement->product;
        if ($product === null) {
            return ['success' => false, 'message' => '产品不存在'];
        }

        // 产品维度打包价：价历按「入住日」一行配置整段连住售价，不对多晚逐日相加
        $hasCheckInPrice = Price::query()
            ->where('product_id', $entitlement->product_id)
            ->where('room_type_id', $roomTypeId)
            ->whereDate('date', $checkInDate)
            ->exists();
        if (!$hasCheckInPrice) {
            return ['success' => false, 'message' => '所选日期无价格'];
        }

        $calculated = $this->productService->calculatePrice($product, $roomTypeId, $checkInDate);
        if ($calculated['sale_price'] <= 0) {
            return ['success' => false, 'message' => '所选日期无价格'];
        }

        $basePrice = (float) $entitlement->base_price;
        $packageSalePrice = round((float) $calculated['sale_price'], 2);
        $surcharge = max(0, round($packageSalePrice - $basePrice, 2));

        return [
            'success' => true,
            'data' => array_merge([
                'entitlement_no' => $entitlement->entitlement_no,
                'hotel_id' => $hotelId ?? $relation?->hotel_id,
                'room_type_id' => $roomTypeId,
                'check_in_date' => $checkInDate,
                'check_out_date' => Carbon::parse($checkInDate)->addDays($stayDays)->format('Y-m-d'),
                'package_sale_price' => $packageSalePrice,
                'package_price_date' => $checkInDate,
                'base_price' => $basePrice,
                'surcharge_amount' => $surcharge,
                'stay_days' => $stayDays,
                'stock_min' => $stockMin === PHP_INT_MAX ? 0 : $stockMin,
            ], ProductIdRegionRestriction::payloadForMp($product)),
        ];
    }

    /**
     * @return array{stay_days:int,candidate_dates:array<int,string>,room_type_ids:array<int,int>}
     */
    private function buildEntitlementContext(OrderEntitlement $entitlement): array
    {
        $entitlement->loadMissing(['order', 'product']);
        $stayDays = max(1, (int) ($entitlement->product?->stay_days ?: 1));
        $today = Carbon::today();

        $product = $entitlement->product;
        $advanceDays = $product !== null && $product->fulfillment_mode === 'deferred'
            ? max(0, (int) ($product->booking_advance_days ?? 0))
            : 0;
        $earliestCheckIn = $product?->earliestCheckInDate($today) ?? $today->copy();

        $window = $this->resolveBookingWindowBounds($entitlement, $today);
        $start = Carbon::parse($window['start']);
        $end = Carbon::parse($window['end']);
        if ($start->lessThan($earliestCheckIn)) {
            $start = $earliestCheckIn->copy();
        }
        // 需要保证完整入住天数都在有效期内
        $lastCheckIn = $end->copy()->subDays($stayDays - 1);
        if ($lastCheckIn->lessThan($start)) {
            $candidateDates = [];
        } else {
            $candidateDates = [];
            $cursor = $start->copy();
            while ($cursor->lessThanOrEqualTo($lastCheckIn)) {
                $candidateDates[] = $cursor->format('Y-m-d');
                $cursor->addDay();
            }
        }

        $roomTypeIds = Price::query()
            ->where('product_id', $entitlement->product_id)
            ->pluck('room_type_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($roomTypeIds)) {
            $roomTypeIds = ProductHotelRelation::query()
                ->where('ticket_product_id', $entitlement->product_id)
                ->where('is_active', true)
                ->pluck('room_type_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return [
            'stay_days' => $stayDays,
            'candidate_dates' => $candidateDates,
            'room_type_ids' => $roomTypeIds,
            'booking_advance_days' => $advanceDays,
            'earliest_check_in_date' => $earliestCheckIn->format('Y-m-d'),
            'latest_check_in_date' => $candidateDates === [] ? null : $candidateDates[array_key_last($candidateDates)],
            'sale_end_date' => $product?->sale_end_date?->format('Y-m-d'),
            'booking_advance_hint' => $product?->bookingAdvanceHint(),
        ];
    }

    /**
     * 合并 OTA 订单备注窗口与产品销售有效期，得到可预约入住的日期上界/下界。
     *
     * @return array{start: string, end: string}
     */
    private function resolveBookingWindowBounds(OrderEntitlement $entitlement, Carbon $today): array
    {
        $otaWindow = $this->extractBookingWindow($entitlement);
        $product = $entitlement->product;

        $start = $otaWindow['start'];
        $end = $otaWindow['end'];

        if ($product !== null) {
            if ($product->sale_start_date !== null) {
                $saleStart = $product->sale_start_date->format('Y-m-d');
                $start = $start === null ? $saleStart : max($start, $saleStart);
            }
            if ($product->sale_end_date !== null) {
                $saleEnd = $product->sale_end_date->format('Y-m-d');
                $end = $end === null ? $saleEnd : min($end, $saleEnd);
            }
        }

        if ($start === null) {
            $start = $today->format('Y-m-d');
        }
        if ($end === null) {
            $end = $today->copy()->addYear()->format('Y-m-d');
        }

        if ($start > $end) {
            $end = $start;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array{start:?string,end:?string}
     */
    private function extractBookingWindow(OrderEntitlement $entitlement): array
    {
        $remark = (string) ($entitlement->order?->remark ?? '');
        if ($remark === '') {
            return ['start' => null, 'end' => null];
        }

        $decoded = json_decode($remark, true);
        if (!is_array($decoded)) {
            return ['start' => null, 'end' => null];
        }

        $start = $decoded['ota_window_start'] ?? null;
        $end = $decoded['ota_window_end'] ?? null;

        return [
            'start' => is_string($start) && $start !== '' ? $start : null,
            'end' => is_string($end) && $end !== '' ? $end : null,
        ];
    }

    /**
     * 月历可约日（批量价库 + 库存，避免按日×房型调用 buildQuote 导致超时）
     *
     * @param  array{stay_days:int,candidate_dates:array<int,string>,room_type_ids:array<int,int>}  $context
     * @return list<array{date: string, available: bool}>
     */
    private function buildCalendarAvailabilityRows(OrderEntitlement $entitlement, array $context): array
    {
        $candidateDates = $context['candidate_dates'];
        $roomTypeIds = $context['room_type_ids'];
        $stayDays = $context['stay_days'];

        if ($candidateDates === [] || $roomTypeIds === []) {
            return array_map(
                static fn (string $date): array => ['date' => $date, 'available' => false],
                $candidateDates,
            );
        }

        $minCheckIn = $candidateDates[0];
        $maxCheckIn = $candidateDates[array_key_last($candidateDates)];
        $maxOccupancyDate = Carbon::parse($maxCheckIn)->addDays($stayDays - 1)->format('Y-m-d');

        /** @var array<string, array<int, true>> $pricedRoomTypesByCheckIn */
        $pricedRoomTypesByCheckIn = [];
        Price::query()
            ->where('product_id', $entitlement->product_id)
            ->whereIn('room_type_id', $roomTypeIds)
            ->whereDate('date', '>=', $minCheckIn)
            ->whereDate('date', '<=', $maxCheckIn)
            ->get(['room_type_id', 'date'])
            ->each(function (Price $price) use (&$pricedRoomTypesByCheckIn): void {
                $checkIn = $price->date->format('Y-m-d');
                $pricedRoomTypesByCheckIn[$checkIn][(int) $price->room_type_id] = true;
            });

        $occupancyDatesForLoad = [];
        foreach ($candidateDates as $checkInDate) {
            foreach ($this->inventoryService->getDateRange($checkInDate, $stayDays) as $occupancyDate) {
                $occupancyDatesForLoad[$occupancyDate] = true;
            }
        }
        $inventoryMap = $this->loadInventoryMap($roomTypeIds, array_keys($occupancyDatesForLoad));

        $rows = [];
        foreach ($candidateDates as $checkInDate) {
            $occupancyDates = $this->inventoryService->getDateRange($checkInDate, $stayDays);
            $roomTypesToTry = array_keys($pricedRoomTypesByCheckIn[$checkInDate] ?? []);
            $available = false;

            foreach ($roomTypesToTry as $roomTypeId) {
                if ($this->roomTypeHasInventoryForStay($roomTypeId, $occupancyDates, $inventoryMap)) {
                    $available = true;
                    break;
                }
            }

            $rows[] = [
                'date' => $checkInDate,
                'available' => $available,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, int>  $roomTypeIds
     * @param  array<int, string>  $occupancyDates
     * @return array<string, Inventory>
     */
    private function loadInventoryMap(array $roomTypeIds, array $occupancyDates): array
    {
        if ($roomTypeIds === [] || $occupancyDates === []) {
            return [];
        }

        $minDate = min($occupancyDates);
        $maxDate = max($occupancyDates);

        $map = [];
        Inventory::query()
            ->whereIn('room_type_id', $roomTypeIds)
            ->whereDate('date', '>=', $minDate)
            ->whereDate('date', '<=', $maxDate)
            ->get()
            ->each(function (Inventory $inventory) use (&$map): void {
                $map[$this->inventoryMapKey((int) $inventory->room_type_id, $inventory->date->format('Y-m-d'))] = $inventory;
            });

        return $map;
    }

    private function inventoryMapKey(int $roomTypeId, string $date): string
    {
        return $roomTypeId.':'.$date;
    }

    /**
     * @param  array<int, string>  $occupancyDates
     * @param  array<string, Inventory>  $inventoryMap
     */
    private function roomTypeHasInventoryForStay(int $roomTypeId, array $occupancyDates, array $inventoryMap): bool
    {
        foreach ($occupancyDates as $date) {
            $inventory = $inventoryMap[$this->inventoryMapKey($roomTypeId, $date)] ?? null;
            if ($inventory === null || $inventory->is_closed || $inventory->available_quantity <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * 连住库存（携程口径）：入住日起连续 stay_days 晚任一晚无房/关房则不可约该入住日
     *
     * @param  array<int, int>  $roomTypeIds
     * @param  array<int, string>  $occupancyDates
     * @param  array<string, Inventory>|null  $inventoryMap
     */
    private function hasInventoryForStay(array $roomTypeIds, array $occupancyDates, ?array $inventoryMap = null): bool
    {
        $inventoryMap ??= $this->loadInventoryMap($roomTypeIds, $occupancyDates);

        foreach ($roomTypeIds as $roomTypeId) {
            if ($this->roomTypeHasInventoryForStay((int) $roomTypeId, $occupancyDates, $inventoryMap)) {
                return true;
            }
        }

        return false;
    }

    private function resolvePayOpenid(Request $request, ?string $loginCode): string
    {
        $token = $this->resolveBearerToken($request);

        // 支付时优先用本次 wx.login 的 code 换 openid，避免 token 中 openid 过期或与 AppID 不一致
        if ($loginCode !== null && $loginCode !== '' && $this->wechatMiniService->isConfigured()) {
            $session = $this->wechatMiniService->codeToSession($loginCode);
            $openid = $session['openid'];
            if ($token !== null) {
                $this->mpAuthService->bindOpenidToToken($token, $openid);
            }

            return $openid;
        }

        $openid = $this->resolveOpenid($request, $this->mpAuthService) ?? '';
        if ($openid !== '') {
            return $openid;
        }

        throw new \InvalidArgumentException(
            '缺少微信 openid：请使用微信手机号快捷登录，并确认小程序 AppID 与后端 WECHAT_MINI_APP_ID 一致',
        );
    }

    /**
     * @return array{guest_id_type: string, guest_id_type_label: string, guest_id_number_label: string, guest_id_card: ?string}
     */
    private function guestDocumentPayload(OrderBooking $booking): array
    {
        $type = $booking->guest_id_type instanceof GuestIdType
            ? $booking->guest_id_type
            : GuestIdType::tryFrom((string) ($booking->guest_id_type ?? '')) ?? GuestIdType::IdCard;

        return [
            'guest_id_type' => $type->value,
            'guest_id_type_label' => $type->label(),
            'guest_id_number_label' => $type->numberLabel(),
            'guest_id_card' => $booking->guest_id_card,
        ];
    }
}

