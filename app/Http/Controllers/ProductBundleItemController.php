<?php

namespace App\Http\Controllers;

use App\Models\ProductBundleItem;
use App\Models\SalesProduct;
use App\Services\SystemPkgPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 打包清单管理控制器
 */
class ProductBundleItemController extends Controller
{
    public function __construct(
        protected SystemPkgPriceService $priceService
    ) {}

    /**
     * 打包清单列表
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'sales_product_id' => 'required|exists:sales_products,id',
        ]);

        $salesProduct = SalesProduct::find($request->sales_product_id);
        
        // 权限控制
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权查看该产品的打包清单',
                ], 403);
            }
        }

        $bundleItems = $salesProduct->bundleItems()
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(function ($item) {
                $itemData = $item->toArray();
                
                if ($item->resource_type === 'TICKET') {
                    $ticket = \App\Models\Ticket::find($item->resource_id);
                    $itemData['resource_name'] = $ticket ? $ticket->name : '-';
                } elseif ($item->resource_type === 'HOTEL') {
                    $roomType = \App\Models\ResRoomType::with('hotel')->find($item->resource_id);
                    if ($roomType && $roomType->hotel) {
                        $itemData['resource_name'] = $roomType->hotel->name . ' - ' . $roomType->name;
                    } else {
                        $itemData['resource_name'] = $roomType ? $roomType->name : '-';
                    }
                }
                
                return $itemData;
            });

        return response()->json([
            'data' => $bundleItems,
        ]);
    }

    /**
     * 添加打包清单项
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sales_product_id' => 'required|exists:sales_products,id',
            'resource_type' => 'required|string|in:TICKET,HOTEL',
            'resource_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $salesProduct = SalesProduct::find($validated['sales_product_id']);
        
        // 权限控制
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权管理该产品的打包清单',
                ], 403);
            }
        }

        // 验证资源是否存在
        if ($validated['resource_type'] === 'TICKET') {
            $resource = \App\Models\Ticket::find($validated['resource_id']);
            if (!$resource) {
                return response()->json([
                    'message' => '门票不存在',
                ], 422);
            }
        } elseif ($validated['resource_type'] === 'HOTEL') {
            $resource = \App\Models\ResRoomType::find($validated['resource_id']);
            if (!$resource) {
                return response()->json([
                    'message' => '房型不存在',
                ], 422);
            }
        }

        // 检查是否已存在（唯一约束）
        $exists = ProductBundleItem::where('sales_product_id', $validated['sales_product_id'])
            ->where('resource_type', $validated['resource_type'])
            ->where('resource_id', $validated['resource_id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => '该资源已存在于打包清单中',
            ], 422);
        }

        $bundleItem = ProductBundleItem::create($validated);

        // 触发价格更新
        \App\Jobs\UpdateSystemPkgPriceJob::dispatch($salesProduct->id);

        return response()->json([
            'message' => '打包清单项添加成功',
            'data' => $bundleItem,
        ], 201);
    }

    /**
     * 更新打包清单项
     */
    public function update(Request $request, ProductBundleItem $productBundleItem): JsonResponse
    {
        // 权限控制
        $salesProduct = $productBundleItem->salesProduct;
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权更新该打包清单项',
                ], 403);
            }
        }

        $validated = $request->validate([
            'quantity' => 'sometimes|required|integer|min:1',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        // 不允许修改 resource_type 和 resource_id
        if (isset($validated['resource_type']) || isset($validated['resource_id'])) {
            unset($validated['resource_type'], $validated['resource_id']);
        }

        $productBundleItem->update($validated);

        // 触发价格更新
        \App\Jobs\UpdateSystemPkgPriceJob::dispatch($salesProduct->id);

        return response()->json([
            'message' => '打包清单项更新成功',
            'data' => $productBundleItem,
        ]);
    }

    /**
     * 删除打包清单项
     */
    public function destroy(ProductBundleItem $productBundleItem): JsonResponse
    {
        // 权限控制
        $salesProduct = $productBundleItem->salesProduct;
        if (request()->user()->isOperator()) {
            $resourceProviderIds = request()->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权删除该打包清单项',
                ], 403);
            }
        }

        $salesProductId = $salesProduct->id;
        $productBundleItem->delete();

        // 触发价格更新
        \App\Jobs\UpdateSystemPkgPriceJob::dispatch($salesProductId);

        return response()->json([
            'message' => '打包清单项删除成功',
        ]);
    }
}

