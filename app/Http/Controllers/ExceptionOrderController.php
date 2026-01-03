<?php

namespace App\Http\Controllers;

use App\Enums\ExceptionOrderStatus;
use App\Models\ExceptionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExceptionOrderController extends Controller
{
    /**
     * 异常订单列表
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExceptionOrder::class);

        $query = ExceptionOrder::with(['order.product', 'order.hotel', 'order.otaPlatform', 'handler']);

        // 权限过滤：非管理员只能查看所属资源方下的所有景区下的异常订单
        if (!$request->user()->isAdmin()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            // 通过订单的产品关联找到景区进行过滤
            $query->whereHas('order.product', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('exception_type')) {
            $query->where('exception_type', $request->exception_type);
        }

        // 默认只显示待处理和处理中的异常订单
        if (!$request->has('status')) {
            $query->whereIn('status', [ExceptionOrderStatus::PENDING->value, ExceptionOrderStatus::PROCESSING->value]);
        }

        $exceptions = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($exceptions);
    }

    /**
     * 异常订单详情
     */
    public function show(ExceptionOrder $exceptionOrder): JsonResponse
    {
        $this->authorize('view', $exceptionOrder);

        $exceptionOrder->load([
            'order.product',
            'order.hotel',
            'order.roomType',
            'order.otaPlatform',
            'order.items',
            'handler',
        ]);

        return response()->json($exceptionOrder);
    }

    /**
     * 开始处理异常订单
     */
    public function startProcessing(Request $request, ExceptionOrder $exceptionOrder): JsonResponse
    {
        $this->authorize('process', $exceptionOrder);

        $exceptionOrder->update([
            'status' => ExceptionOrderStatus::PROCESSING,
            'handler_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => '已开始处理异常订单',
            'data' => $exceptionOrder->load('handler'),
        ]);
    }

    /**
     * 解决异常订单
     */
    public function resolve(Request $request, ExceptionOrder $exceptionOrder): JsonResponse
    {
        $this->authorize('process', $exceptionOrder);

        $validated = $request->validate([
            'remark' => 'nullable|string',
        ]);

        $exceptionOrder->update([
            'status' => ExceptionOrderStatus::RESOLVED,
            'handler_id' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json([
            'message' => '异常订单已解决',
            'data' => $exceptionOrder->load('handler'),
        ]);
    }
}