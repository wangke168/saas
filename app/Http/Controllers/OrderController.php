<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\OrderOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'product', 
            'hotel.scenicSpot.softwareProvider', 
            'roomType'
        ]);

        // 权限过滤：非管理员只能查看所属资源方下的所有景区下的订单
        if (!$request->user()->isAdmin()) {
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

        if ($request->has('check_in_date')) {
            $query->where('check_in_date', $request->check_in_date);
        }

        if ($request->has('order_no')) {
            $query->where('order_no', 'like', '%' . $request->order_no . '%');
        }

        if ($request->has('ota_order_no')) {
            $query->where('ota_order_no', 'like', '%' . $request->ota_order_no . '%');
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($orders);
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
        ]);

        return response()->json($order);
    }

    /**
     * 更新订单状态
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        $validated = $request->validate([
            'status' => ['required', 'in:' . implode(',', array_column(OrderStatus::cases(), 'value'))],
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
        if (!in_array($order->status, [OrderStatus::PAID_PENDING, OrderStatus::CONFIRMING])) {
            return response()->json([
                'success' => false,
                'message' => '订单状态不允许接单，当前状态：' . $order->status->label(),
            ], 400);
        }

        $validated = $request->validate([
            'remark' => 'nullable|string|max:500',
        ]);

        $result = $this->orderOperationService->confirmOrder(
            $order,
            $validated['remark'] ?? null,
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
     * 拒单（拒绝订单）
     */
    public function rejectOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        // 检查订单状态是否允许拒单
        if (!in_array($order->status, [OrderStatus::PAID_PENDING, OrderStatus::CONFIRMING])) {
            return response()->json([
                'success' => false,
                'message' => '订单状态不允许拒单，当前状态：' . $order->status->label(),
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
                'message' => '订单状态不允许核销，当前状态：' . $order->status->label(),
            ], 400);
        }

        $validated = $request->validate([
            'use_start_date' => 'required|date',
            'use_end_date' => 'required|date|after:use_start_date',
            'use_quantity' => 'required|integer|min:1|max:' . $order->room_count,
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
                'message' => '订单状态不允许同意取消，当前状态：' . $order->status->label(),
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
                'message' => '订单状态不允许拒绝取消，当前状态：' . $order->status->label(),
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
}
