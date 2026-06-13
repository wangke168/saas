<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Requests\OrderExportRequest;
use App\Models\Order;
use App\Models\OrderBooking;
use App\Services\OrderExportService;
use App\Services\OrderOperationService;
use App\Services\OrderService;
use App\Services\Presale\PresaleFulfillmentOrderService;
use App\Support\ManualResourceOrderNo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
        protected OrderOperationService $orderOperationService
    ) {}

    /**
     * 订单列表
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $query = Order::with([
            'otaPlatform',
            'product.scenicSpot',
            'hotel.scenicSpot.softwareProvider',
            'roomType',
        ]);

        // 权限过滤：非管理员只能查看所属资源方下的所有景区下的订单
        if (! $request->user()->isAdmin()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');

            $query->whereHas('product', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('ota_platform_id')) {
            $query->where('ota_platform_id', $request->ota_platform_id);
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // 入住日期范围查询
        if ($request->has('check_in_date_start')) {
            $query->where('check_in_date', '>=', $request->check_in_date_start);
        }
        if ($request->has('check_in_date_end')) {
            $query->where('check_in_date', '<=', $request->check_in_date_end);
        }

        // 预定日期范围查询
        if ($request->has('created_at_start')) {
            $query->whereDate('created_at', '>=', $request->created_at_start);
        }
        if ($request->has('created_at_end')) {
            $query->whereDate('created_at', '<=', $request->created_at_end);
        }

        // 客人姓名查询
        if ($request->has('contact_name')) {
            $query->where('contact_name', 'like', '%'.$request->contact_name.'%');
        }

        // 客人手机号查询（trim 后精准匹配）
        $contactPhone = trim((string) $request->input('contact_phone', ''));
        if ($contactPhone !== '') {
            $query->where('contact_phone', $contactPhone);
        }

        // 景区查询（通过product关联）
        if ($request->has('scenic_spot_id')) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('scenic_spot_id', $request->scenic_spot_id);
            });
        }

        if ($request->has('order_no')) {
            $query->where('order_no', 'like', '%'.$request->order_no.'%');
        }

        if ($request->has('ota_order_no')) {
            $query->where('ota_order_no', 'like', '%'.$request->ota_order_no.'%');
        }

        $bookingNo = trim((string) $request->input('booking_no', ''));
        if ($bookingNo !== '') {
            $query->whereHas('entitlements.booking', function ($q) use ($bookingNo) {
                $q->where('booking_no', 'like', '%'.$bookingNo.'%');
            });
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        $orders->getCollection()->transform(function (Order $order): Order {
            $flags = PresaleFulfillmentOrderService::presaleDisplayFlags($order);
            $order->setAttribute('is_presale_parent', $flags['is_presale_parent']);
            $order->setAttribute('is_presale_fulfillment_child', $flags['is_presale_fulfillment_child']);
            $order->setAttribute(
                'requires_resource_order_no_input',
                ManualResourceOrderNo::needsResourceOrderNoOnConfirm($order)
            );

            return $order;
        });

        return response()->json($orders);
    }

    /**
     * 导出订单（对账）
     */
    public function export(OrderExportRequest $request, OrderExportService $orderExportService): Response
    {
        $this->authorize('viewAny', Order::class);

        return $orderExportService->export($request);
    }

    /**
     * 订单详情
     */
    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load([
            'otaPlatform',
            'product',
            'hotel',
            'roomType',
            'hotel.scenicSpot.softwareProvider',
            'items',
            'logs.operator',
            'exceptionOrder',
            'entitlements.booking.hotel',
            'entitlements.booking.roomType',
            'entitlements.booking.fulfilledOrder',
            'fulfillmentChildren.hotel',
            'fulfillmentChildren.roomType',
        ]);

        $payload = $order->toArray();
        $presaleFlags = PresaleFulfillmentOrderService::presaleDisplayFlags($order);
        $payload['is_presale_parent'] = $presaleFlags['is_presale_parent'];
        $payload['is_presale_fulfillment_child'] = $presaleFlags['is_presale_fulfillment_child'];

        if ($presaleFlags['is_presale_fulfillment_child']) {
            $order->loadMissing('presaleParent.otaPlatform');
            $parent = $order->presaleParent;
            $sourceBooking = OrderBooking::query()
                ->where('fulfilled_order_id', $order->id)
                ->first();

            $payload['presale_parent'] = $parent === null ? null : [
                'id' => $parent->id,
                'order_no' => $parent->order_no,
                'ota_order_no' => $parent->ota_order_no,
                'ota_platform_name' => $parent->otaPlatform?->name,
            ];
            $payload['source_booking'] = $sourceBooking === null ? null : [
                'booking_no' => $sourceBooking->booking_no,
                'base_price' => (float) $sourceBooking->base_price,
                'surcharge_amount' => (float) $sourceBooking->surcharge_amount,
                'package_sale_price' => (float) $sourceBooking->package_sale_price,
            ];
        }
        $payload['presale_entitlements'] = $order->entitlements
            ->map(static function ($entitlement) {
                $booking = $entitlement->booking;

                return [
                    'entitlement_no' => $entitlement->entitlement_no,
                    'status' => $entitlement->status instanceof \BackedEnum
                        ? $entitlement->status->value
                        : (string) $entitlement->status,
                    'base_price' => (float) $entitlement->base_price,
                    'booked_at' => $entitlement->booked_at?->toDateTimeString(),
                    'booking' => $booking === null ? null : [
                        'id' => $booking->id,
                        'booking_no' => $booking->booking_no,
                        'status' => $booking->status instanceof \BackedEnum
                            ? $booking->status->value
                            : (string) $booking->status,
                        'check_in_date' => $booking->check_in_date?->format('Y-m-d'),
                        'check_out_date' => $booking->check_out_date?->format('Y-m-d'),
                        'guest_name' => $booking->guest_name,
                        'guest_phone' => $booking->guest_phone,
                        'hotel_name' => $booking->hotel?->name,
                        'room_type_name' => $booking->roomType?->name,
                        'surcharge_amount' => (float) $booking->surcharge_amount,
                        'fulfilled_order_id' => $booking->fulfilled_order_id,
                        'fulfillment_order_no' => $booking->fulfilledOrder?->order_no,
                    ],
                ];
            })
            ->values()
            ->all();

        if ($payload['is_presale_parent']) {
            $payload['fulfillment_orders'] = $order->fulfillmentChildren
                ->map(static fn ($child) => [
                    'id' => $child->id,
                    'order_no' => $child->order_no,
                    'ota_order_no' => $child->ota_order_no,
                    'status' => $child->status instanceof \BackedEnum ? $child->status->value : (string) $child->status,
                    'check_in_date' => $child->check_in_date?->format('Y-m-d'),
                    'check_out_date' => $child->check_out_date?->format('Y-m-d'),
                    'hotel_name' => $child->hotel?->name,
                    'room_type_name' => $child->roomType?->name,
                    'resource_order_no' => $child->resource_order_no,
                ])
                ->values()
                ->all();
        }

        $payload['can_backfill_resource_order_no'] = ManualResourceOrderNo::canBackfillResourceOrderNo($order);

        return response()->json($payload);
    }

    /**
     * 更新订单状态
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        $validated = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_column(OrderStatus::cases(), 'value'))],
            'remark' => 'nullable|string',
        ]);

        $this->orderService->updateOrderStatus(
            $order,
            OrderStatus::from($validated['status']),
            $validated['remark'] ?? null,
            $request->user()->id
        );

        $order->refresh();
        $order->load(['logs']);

        return response()->json([
            'message' => '订单状态更新成功',
            'data' => $order,
        ]);
    }

    /**
     * 接单（确认订单）
     */
    public function confirmOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        // 检查订单状态是否允许接单
        if (! in_array($order->status, [OrderStatus::PAID_PENDING, OrderStatus::CONFIRMING])) {
            return response()->json([
                'success' => false,
                'message' => '订单状态不允许接单，当前状态：'.$order->status->label(),
            ], 400);
        }

        $validated = $request->validate([
            'remark' => 'nullable|string|max:500',
            'resource_order_no' => 'nullable|string|max:100',
        ]);

        if (ManualResourceOrderNo::needsResourceOrderNoOnConfirm($order)
            && empty(trim((string) ($validated['resource_order_no'] ?? '')))) {
            return response()->json([
                'success' => false,
                'message' => '请填写资源方订单号',
            ], 422);
        }

        if (! empty($validated['resource_order_no'])
            && ! ManualResourceOrderNo::isPlaceholder($order->resource_order_no)
            && $order->resource_order_no) {
            return response()->json([
                'success' => false,
                'message' => '订单已有资源方订单号，不可覆盖',
            ], 422);
        }

        $result = $this->orderOperationService->confirmOrder(
            $order,
            $validated['remark'] ?? null,
            $request->user()->id,
            isset($validated['resource_order_no']) ? trim($validated['resource_order_no']) : null,
        );

        if ($result['success']) {
            $order->refresh();
            $order->load(['logs']);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $order,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ], 400);
    }

    /**
     * 补录资源方订单号
     */
    public function backfillResourceOrderNo(Request $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        $validated = $request->validate([
            'resource_order_no' => 'required|string|max:100',
        ]);

        $result = $this->orderOperationService->backfillResourceOrderNo(
            $order,
            trim($validated['resource_order_no']),
            $request->user()->id,
        );

        if ($result['success']) {
            $order->refresh();
            $order->load(['logs']);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $order,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ], 422);
    }

    /**
     * 拒单（拒绝订单）
     */
    public function rejectOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        // 检查订单状态是否允许拒单
        if (! in_array($order->status, [OrderStatus::PAID_PENDING, OrderStatus::CONFIRMING])) {
            return response()->json([
                'success' => false,
                'message' => '订单状态不允许拒单，当前状态：'.$order->status->label(),
            ], 400);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $result = $this->orderOperationService->rejectOrder(
            $order,
            $validated['reason'],
            $request->user()->id
        );

        if ($result['success']) {
            $order->refresh();
            $order->load(['logs']);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $order,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ], 400);
    }

    /**
     * 核销订单
     */
    public function verifyOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        // 检查订单状态是否允许核销
        if ($order->status !== OrderStatus::CONFIRMED) {
            return response()->json([
                'success' => false,
                'message' => '订单状态不允许核销，当前状态：'.$order->status->label(),
            ], 400);
        }

        $validated = $request->validate([
            'use_start_date' => 'required|date',
            'use_end_date' => 'required|date|after:use_start_date',
            'use_quantity' => 'required|integer|min:1|max:'.$order->room_count,
            'passengers' => 'nullable|array',
            'vouchers' => 'nullable|array',
        ]);

        $result = $this->orderOperationService->verifyOrder(
            $order,
            $validated,
            $request->user()->id
        );

        if ($result['success']) {
            $order->refresh();
            $order->load(['logs']);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $order,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ], 400);
    }

    /**
     * 同意取消订单
     */
    public function approveCancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        // 检查订单状态是否允许取消
        if ($order->status !== OrderStatus::CANCEL_REQUESTED) {
            return response()->json([
                'success' => false,
                'message' => '订单状态不允许同意取消，当前状态：'.$order->status->label(),
            ], 400);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->orderOperationService->cancelOrder(
            $order,
            $validated['reason'] ?? '人工同意取消',
            $request->user()->id,
            true // approve = true
        );

        if ($result['success']) {
            $order->refresh();
            $order->load(['logs']);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $order,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ], 400);
    }

    /**
     * 拒绝取消订单
     */
    public function rejectCancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        // 检查订单状态是否允许拒绝取消
        if ($order->status !== OrderStatus::CANCEL_REQUESTED) {
            return response()->json([
                'success' => false,
                'message' => '订单状态不允许拒绝取消，当前状态：'.$order->status->label(),
            ], 400);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $result = $this->orderOperationService->cancelOrder(
            $order,
            $validated['reason'],
            $request->user()->id,
            false // approve = false
        );

        if ($result['success']) {
            $order->refresh();
            $order->load(['logs']);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $order,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ], 400);
    }

    /**
     * 删除订单（软删除）
     */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->authorize('delete', $order);

        $order->delete();

        return response()->json([
            'success' => true,
            'message' => '订单删除成功',
        ]);
    }
}
