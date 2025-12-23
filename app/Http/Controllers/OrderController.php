<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    /**
     * 订单列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['otaPlatform', 'product', 'hotel', 'roomType', 'resourceProvider']);

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
        $order->load([
            'otaPlatform',
            'product',
            'hotel',
            'roomType',
            'resourceProvider',
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
}
