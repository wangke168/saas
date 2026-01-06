<?php

namespace App\Http\Controllers;

use App\Models\SalesProduct;
use App\Services\SystemPkgPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 系统打包产品管理控制器
 * 复用现有产品管理权限逻辑
 */
class SalesProductController extends Controller
{
    public function __construct(
        protected SystemPkgPriceService $priceService
    ) {}

    /**
     * 产品列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalesProduct::with(['scenicSpot']);

        // 权限控制：运营只能查看所属资源方下的所有景区下的产品（复用旧业务逻辑）
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            $query->whereIn('scenic_spot_id', $scenicSpotIds);
        }

        // 搜索功能
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('ota_product_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('scenic_spot_id')) {
            $query->where('scenic_spot_id', $request->scenic_spot_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        $products = $query->paginate($request->get('per_page', 15));

        return response()->json($products);
    }

    /**
     * 创建产品
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'ota_product_code' => 'required|string|max:50|unique:sales_products,ota_product_code',
            'product_name' => 'required|string|max:100',
            'stay_days' => 'nullable|integer|min:1',
            'sale_start_date' => 'required|date',
            'sale_end_date' => 'required|date|after_or_equal:sale_start_date',
            'description' => 'nullable|string',
            'status' => 'sometimes|integer|in:0,1',
        ]);

        // 权限控制：运营只能在自己所属资源方下的景区下创建产品（复用旧业务逻辑）
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($validated['scenic_spot_id'])) {
                return response()->json([
                    'message' => '无权在该景区下创建产品',
                ], 403);
            }
        }

        // 验证编码格式：必须以 PKG_ 开头
        if (strpos($validated['ota_product_code'], 'PKG_') !== 0) {
            return response()->json([
                'message' => '产品编码必须以 PKG_ 开头',
            ], 422);
        }

        $product = SalesProduct::create($validated);
        $product->load(['scenicSpot']);

        return response()->json([
            'message' => '产品创建成功',
            'data' => $product,
        ], 201);
    }

    /**
     * 产品详情
     */
    public function show(SalesProduct $salesProduct): JsonResponse
    {
        $salesProduct->load([
            'scenicSpot',
            'bundleItems' => function ($query) {
                $query->orderBy('sort_order', 'asc');
            },
        ]);
        
        // 权限控制
        if (request()->user()->isOperator()) {
            $resourceProviderIds = request()->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权查看该产品',
                ], 403);
            }
        }
        
        return response()->json([
            'data' => $salesProduct,
        ]);
    }

    /**
     * 更新产品
     */
    public function update(Request $request, SalesProduct $salesProduct): JsonResponse
    {
        // 权限控制
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权更新该产品',
                ], 403);
            }
        }

        $validated = $request->validate([
            'product_name' => 'sometimes|required|string|max:100',
            'stay_days' => 'sometimes|integer|min:1',
            'sale_start_date' => 'required|date',
            'sale_end_date' => 'required|date|after_or_equal:sale_start_date',
            'description' => 'nullable|string',
            'status' => 'sometimes|integer|in:0,1',
        ]);

        // 不允许修改 ota_product_code
        if (isset($validated['ota_product_code'])) {
            unset($validated['ota_product_code']);
        }

        $salesProduct->update($validated);
        $salesProduct->load(['scenicSpot']);

        // 如果修改了 stay_days 或销售日期，需要更新价格日历
        if (isset($validated['stay_days']) || isset($validated['sale_start_date']) || isset($validated['sale_end_date'])) {
            \App\Jobs\UpdateSystemPkgPriceJob::dispatch($salesProduct->id);
        }

        return response()->json([
            'message' => '产品更新成功',
            'data' => $salesProduct,
        ]);
    }

    /**
     * 删除产品
     */
    public function destroy(SalesProduct $salesProduct): JsonResponse
    {
        // 权限控制
        if (request()->user()->isOperator()) {
            $resourceProviderIds = request()->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权删除该产品',
                ], 403);
            }
        }

        $salesProduct->delete();

        return response()->json([
            'message' => '产品删除成功',
        ]);
    }
}

