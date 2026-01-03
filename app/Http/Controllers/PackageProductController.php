<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\PackageProductService;
use App\Services\ProductHotelRelationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PackageProductController extends Controller
{
    public function __construct(
        protected PackageProductService $packageProductService,
        protected ProductHotelRelationService $relationService
    ) {}

    /**
     * 打包产品列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::where('product_type', 'package')
            ->with(['scenicSpot', 'packageProduct']);

        // 权限控制：运营只能查看所属资源方下的所有景区下的产品
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
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('external_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('scenic_spot_id')) {
            $query->where('scenic_spot_id', $request->scenic_spot_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        $products = $query->paginate($request->get('per_page', 15));

        // 加载关联数据
        $products->load(['packageProduct.ticketProduct', 'packageProduct.hotelProduct', 'packageProduct.hotel', 'packageProduct.roomType']);

        return response()->json($products);
    }

    /**
     * 打包产品详情
     */
    public function show(Product $packageProduct): JsonResponse
    {
        // 确保是打包产品
        if (!$packageProduct->isPackage()) {
            return response()->json([
                'message' => '该产品不是打包产品',
            ], 400);
        }

        // 加载关联数据
        $packageProduct->load([
            'scenicSpot',
            'packageProduct.ticketProduct',
            'packageProduct.hotelProduct',
            'packageProduct.hotel',
            'packageProduct.roomType',
        ]);

        return response()->json([
            'data' => $packageProduct,
        ]);
    }

    /**
     * 创建打包产品
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:products,code',
            'external_code' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'ticket_product_id' => 'required|exists:products,id',
            'hotel_product_id' => 'required|exists:products,id',
            'hotel_id' => 'required|exists:hotels,id',
            'room_type_id' => 'required|exists:room_types,id',
            'resource_service_type' => 'nullable|string|max:50',
            'stay_days' => 'nullable|integer|min:1|max:30',
            'is_active' => 'boolean',
        ]);

        // 验证门票产品类型
        $ticketProduct = Product::find($validated['ticket_product_id']);
        if (!$ticketProduct || !$ticketProduct->isTicket()) {
            return response()->json([
                'message' => '指定的门票产品不存在或类型不正确',
            ], 400);
        }

        // 验证酒店产品类型
        $hotelProduct = Product::find($validated['hotel_product_id']);
        if (!$hotelProduct || !$hotelProduct->isHotel()) {
            return response()->json([
                'message' => '指定的酒店产品不存在或类型不正确',
            ], 400);
        }

        // 权限控制
        $policy = app(\App\Policies\ProductPolicy::class);
        if (!$policy->create($request->user(), $validated['scenic_spot_id'])) {
            abort(403, '无权在该景区下创建产品');
        }

        try {
            $packageProduct = $this->packageProductService->createPackageProduct($validated);

            return response()->json([
                'message' => '打包产品创建成功',
                'data' => $packageProduct,
            ], 201);
        } catch (\Exception $e) {
            Log::error('创建打包产品失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => '创建失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 更新打包产品
     */
    public function update(Request $request, Product $packageProduct): JsonResponse
    {
        // 确保是打包产品
        if (!$packageProduct->isPackage()) {
            return response()->json([
                'message' => '该产品不是打包产品',
            ], 400);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'ticket_product_id' => 'sometimes|required|exists:products,id',
            'hotel_product_id' => 'sometimes|required|exists:products,id',
            'hotel_id' => 'sometimes|required|exists:hotels,id',
            'room_type_id' => 'sometimes|required|exists:room_types,id',
            'resource_service_type' => 'nullable|string|max:50',
            'stay_days' => 'nullable|integer|min:1|max:30',
            'is_active' => 'sometimes|boolean',
        ]);

        // 权限控制
        $policy = app(\App\Policies\ProductPolicy::class);
        if (!$policy->update($request->user(), $packageProduct)) {
            abort(403, '无权更新该产品');
        }

        try {
            $packageProduct = $this->packageProductService->updatePackageProduct($packageProduct, $validated);

            return response()->json([
                'message' => '打包产品更新成功',
                'data' => $packageProduct,
            ]);
        } catch (\Exception $e) {
            Log::error('更新打包产品失败', [
                'product_id' => $packageProduct->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => '更新失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 删除打包产品
     */
    public function destroy(Product $packageProduct): JsonResponse
    {
        // 确保是打包产品
        if (!$packageProduct->isPackage()) {
            return response()->json([
                'message' => '该产品不是打包产品',
            ], 400);
        }

        // 权限控制
        $this->authorize('delete', $packageProduct);

        try {
            $packageProduct->delete();

            return response()->json([
                'message' => '打包产品删除成功',
            ]);
        } catch (\Exception $e) {
            Log::error('删除打包产品失败', [
                'product_id' => $packageProduct->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => '删除失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 获取打包产品的酒店关联列表
     */
    public function getHotelRelations(Product $packageProduct): JsonResponse
    {
        // 确保是打包产品
        if (!$packageProduct->isPackage()) {
            return response()->json([
                'message' => '该产品不是打包产品',
            ], 400);
        }

        // 获取门票产品关联的酒店列表
        $packageConfig = $packageProduct->packageProduct;
        if (!$packageConfig) {
            return response()->json([
                'data' => [],
            ]);
        }

        $relations = \App\Models\ProductHotelRelation::where('ticket_product_id', $packageConfig->ticket_product_id)
            ->with(['hotelProduct', 'hotel', 'roomType'])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => $relations,
        ]);
    }

    /**
     * 添加酒店关联
     */
    public function addHotelRelation(Request $request, Product $packageProduct): JsonResponse
    {
        // 确保是打包产品
        if (!$packageProduct->isPackage()) {
            return response()->json([
                'message' => '该产品不是打包产品',
            ], 400);
        }

        $validated = $request->validate([
            'hotel_product_id' => 'required|exists:products,id',
            'hotel_id' => 'required|exists:hotels,id',
            'room_type_id' => 'required|exists:room_types,id',
            'resource_service_type' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer',
        ]);

        $packageConfig = $packageProduct->packageProduct;
        if (!$packageConfig) {
            return response()->json([
                'message' => '打包产品配置不存在',
            ], 400);
        }

        try {
            $relation = $this->relationService->createRelation([
                'ticket_product_id' => $packageConfig->ticket_product_id,
                'hotel_product_id' => $validated['hotel_product_id'],
                'hotel_id' => $validated['hotel_id'],
                'room_type_id' => $validated['room_type_id'],
                'resource_service_type' => $validated['resource_service_type'] ?? null,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            return response()->json([
                'message' => '关联添加成功',
                'data' => $relation,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '添加失败：' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * 删除酒店关联
     */
    public function removeHotelRelation(Product $packageProduct, \App\Models\ProductHotelRelation $relation): JsonResponse
    {
        // 确保是打包产品
        if (!$packageProduct->isPackage()) {
            return response()->json([
                'message' => '该产品不是打包产品',
            ], 400);
        }

        try {
            $relation->delete();

            return response()->json([
                'message' => '关联删除成功',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '删除失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 推送到OTA平台
     */
    public function pushToOta(Request $request, Product $packageProduct): JsonResponse
    {
        // 确保是打包产品
        if (!$packageProduct->isPackage()) {
            return response()->json([
                'message' => '该产品不是打包产品',
            ], 400);
        }

        // TODO: 实现打包产品推送到OTA平台的逻辑
        return response()->json([
            'message' => '功能开发中',
        ], 501);
    }
}

