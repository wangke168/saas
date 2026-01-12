<?php

namespace App\Http\Controllers;

use App\Enums\SystemPkgExceptionOrderStatus;
use App\Enums\SystemPkgExceptionOrderType;
use App\Models\SystemPkgExceptionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 系统打包异常订单管理控制器
 * 人工处理，一直保留直到人工处理
 */
class SystemPkgExceptionOrderController extends Controller
{
    /**
     * 异常订单列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = SystemPkgExceptionOrder::with([
            'order.salesProduct.scenicSpot',
            'order.otaPlatform',
            'handler',
        ]);

        // 权限控制：运营只能查看所属资源方下的所有景区下的异常订单
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            $query->whereHas('order.salesProduct', function ($q) use ($scenicSpotIds) {
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
            $query->whereIn('status', [
                SystemPkgExceptionOrderStatus::PENDING->value,
                SystemPkgExceptionOrderStatus::PROCESSING->value,
            ]);
        }

        $exceptions = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($exceptions);
    }

    /**
     * 异常订单详情
     */
    public function show(SystemPkgExceptionOrder $systemPkgExceptionOrder): JsonResponse
    {
        // 权限控制
        if (request()->user()->isOperator()) {
            $resourceProviderIds = request()->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($systemPkgExceptionOrder->order->salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权查看该异常订单',
                ], 403);
            }
        }

        $systemPkgExceptionOrder->load([
            'order.salesProduct.scenicSpot',
            'order.otaPlatform',
            'order.orderItems',
            'handler',
        ]);

        return response()->json([
            'data' => $systemPkgExceptionOrder,
        ]);
    }

    /**
     * 开始处理异常订单
     */
    public function startProcessing(Request $request, SystemPkgExceptionOrder $systemPkgExceptionOrder): JsonResponse
    {
        // 权限控制
        if (request()->user()->isOperator()) {
            $resourceProviderIds = request()->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($systemPkgExceptionOrder->order->salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权处理该异常订单',
                ], 403);
            }
        }

        if ($systemPkgExceptionOrder->status !== SystemPkgExceptionOrderStatus::PENDING->value) {
            return response()->json([
                'message' => '只有待处理的异常订单才能开始处理',
            ], 422);
        }

        $systemPkgExceptionOrder->update([
            'status' => SystemPkgExceptionOrderStatus::PROCESSING->value,
            'handler_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => '已开始处理异常订单',
            'data' => $systemPkgExceptionOrder,
        ]);
    }

    /**
     * 解决异常订单
     */
    public function resolve(Request $request, SystemPkgExceptionOrder $systemPkgExceptionOrder): JsonResponse
    {
        // 权限控制
        if (request()->user()->isOperator()) {
            $resourceProviderIds = request()->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($systemPkgExceptionOrder->order->salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权处理该异常订单',
                ], 403);
            }
        }

        $validated = $request->validate([
            'remark' => 'nullable|string',
        ]);

        $systemPkgExceptionOrder->update([
            'status' => SystemPkgExceptionOrderStatus::RESOLVED->value,
            'handler_id' => $request->user()->id,
            'resolved_at' => now(),
            'remark' => $validated['remark'] ?? null,
        ]);

        return response()->json([
            'message' => '异常订单已解决',
            'data' => $systemPkgExceptionOrder,
        ]);
    }
}


