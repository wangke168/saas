<?php

namespace App\Http\Controllers;

use App\Enums\SystemPkgOrderStatus;
use App\Models\SystemPkgOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 系统打包订单管理控制器
 */
class SystemPkgOrderController extends Controller
{
    /**
     * 订单列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = SystemPkgOrder::with(['otaPlatform', 'salesProduct.scenicSpot']);

        // 权限控制：运营只能查看所属资源方下的所有景区下的订单
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            $query->whereHas('salesProduct', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }

        // 搜索功能
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_no', 'like', "%{$search}%")
                  ->orWhere('ota_order_no', 'like', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('scenic_spot_id')) {
            $query->whereHas('salesProduct', function ($q) use ($request) {
                $q->where('scenic_spot_id', $request->scenic_spot_id);
            });
        }

        if ($request->has('check_in_date_from')) {
            $query->where('check_in_date', '>=', $request->check_in_date_from);
        }

        if ($request->has('check_in_date_to')) {
            $query->where('check_in_date', '<=', $request->check_in_date_to);
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        $orders = $query->paginate($request->get('per_page', 15));

        return response()->json($orders);
    }

    /**
     * 订单详情
     */
    public function show(SystemPkgOrder $systemPkgOrder): JsonResponse
    {
        // 权限控制
        if (request()->user()->isOperator()) {
            $resourceProviderIds = request()->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($systemPkgOrder->salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权查看该订单',
                ], 403);
            }
        }

        $systemPkgOrder->load([
            'otaPlatform',
            'salesProduct.scenicSpot',
            'orderItems',
            'exceptionOrders',
        ]);

        return response()->json([
            'data' => $systemPkgOrder,
        ]);
    }
}

