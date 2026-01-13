<?php

namespace App\Http\Controllers;

use App\Models\Pkg\PkgOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PkgOrderController extends Controller
{
    /**
     * 打包订单列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = PkgOrder::with([
            'otaPlatform',
            'product.scenicSpot',
            'hotel',
            'roomType',
            'items',
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

        // 筛选条件
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('ota_platform_id')) {
            $query->where('ota_platform_id', $request->ota_platform_id);
        }

        if ($request->has('pkg_product_id')) {
            $query->where('pkg_product_id', $request->pkg_product_id);
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
     * 打包订单详情
     */
    public function show(PkgOrder $pkgOrder): JsonResponse
    {
        $pkgOrder->load([
            'otaPlatform',
            'product.scenicSpot',
            'hotel',
            'roomType',
            'items',
            'exceptionOrders',
        ]);

        return response()->json($pkgOrder);
    }
}

