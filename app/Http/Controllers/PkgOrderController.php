<?php

namespace App\Http\Controllers;

use App\Models\Pkg\PkgOrder;
use App\Models\Pkg\PkgOrderItem;
use App\Services\Pkg\PkgOrderItemService;
use App\Jobs\Pkg\ProcessSplitOrderJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PkgOrderController extends Controller
{
    public function __construct(
        protected PkgOrderItemService $pkgOrderItemService
    ) {}
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

    /**
     * 订单项接单
     */
    public function confirmOrderItem(Request $request, PkgOrder $pkgOrder, PkgOrderItem $item): JsonResponse
    {
        // 验证订单项属于该订单
        if ($item->order_id !== $pkgOrder->id) {
            return response()->json([
                'success' => false,
                'message' => '订单项不属于该订单',
            ], 400);
        }

        $result = $this->pkgOrderItemService->confirmItem($item, $request->user()->id);

        if ($result['success']) {
            $item->refresh();
            return response()->json($result);
        }

        return response()->json($result, 400);
    }

    /**
     * 订单项核销
     */
    public function verifyOrderItem(Request $request, PkgOrder $pkgOrder, PkgOrderItem $item): JsonResponse
    {
        // 验证订单项属于该订单
        if ($item->order_id !== $pkgOrder->id) {
            return response()->json([
                'success' => false,
                'message' => '订单项不属于该订单',
            ], 400);
        }

        $validated = $request->validate([
            'use_date' => 'required|date',
            'use_quantity' => 'required|integer|min:1',
            'passengers' => 'nullable|array',
        ]);

        $result = $this->pkgOrderItemService->verifyItem($item, $validated, $request->user()->id);

        if ($result['success']) {
            $item->refresh();
            return response()->json($result);
        }

        return response()->json($result, 400);
    }

    /**
     * 订单项重试
     */
    public function retryOrderItem(Request $request, PkgOrder $pkgOrder, PkgOrderItem $item): JsonResponse
    {
        // 验证订单项属于该订单
        if ($item->order_id !== $pkgOrder->id) {
            return response()->json([
                'success' => false,
                'message' => '订单项不属于该订单',
            ], 400);
        }

        // 验证状态
        if ($item->status !== \App\Enums\PkgOrderItemStatus::FAILED) {
            return response()->json([
                'success' => false,
                'message' => '订单项状态不允许重试，当前状态：' . $item->status->label(),
            ], 400);
        }

        // 重新派发处理任务
        try {
            ProcessSplitOrderJob::dispatch($pkgOrder->id);

            return response()->json([
                'success' => true,
                'message' => '已重新提交处理任务',
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('PkgOrderController: 重试订单项失败', [
                'pkg_order_id' => $pkgOrder->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '重试失败：' . $e->getMessage(),
            ], 500);
        }
    }
}

