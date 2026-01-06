<?php

namespace App\Http\Controllers;

use App\Models\Pkg\PkgProduct;
use App\Models\Pkg\PkgProductBundleItem;
use App\Models\Pkg\PkgProductHotelRoomType;
use App\Services\Pkg\PkgProductPriceService;
use App\Jobs\Pkg\CalculateProductDailyPricesJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PkgProductController extends Controller
{
    /**
     * 打包产品列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = PkgProduct::with(['scenicSpot', 'bundleItems.ticket', 'hotelRoomTypes.hotel', 'hotelRoomTypes.roomType']);

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
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('product_code', 'like', "%{$search}%");
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
     * 打包产品详情
     */
    public function show(PkgProduct $pkgProduct): JsonResponse
    {
        $pkgProduct->load([
            'scenicSpot',
            'bundleItems.ticket',
            'hotelRoomTypes.hotel',
            'hotelRoomTypes.roomType',
        ]);
        
        return response()->json([
            'data' => $pkgProduct,
        ]);
    }

    /**
     * 创建打包产品
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'product_name' => 'required|string|max:100',
            'product_code' => 'nullable|string|max:50|unique:pkg_products,product_code',
            'stay_days' => 'nullable|integer|min:1|max:30',
            'description' => 'nullable|string',
            'status' => 'nullable|integer|in:0,1',
            'bundle_items' => 'required|array|min:1',
            'bundle_items.*.ticket_id' => 'required|exists:tickets,id',
            'bundle_items.*.quantity' => 'nullable|integer|min:1',
            'hotel_room_types' => 'required|array|min:1',
            'hotel_room_types.*.hotel_id' => 'required|exists:res_hotels,id',
            'hotel_room_types.*.room_type_id' => 'required|exists:res_room_types,id',
        ]);

        return DB::transaction(function () use ($validated) {
            // 生成产品编码（如果未提供）
            if (empty($validated['product_code'])) {
                $validated['product_code'] = 'PKG' . date('Ymd') . strtoupper(Str::random(6));
                // 确保唯一性
                while (PkgProduct::where('product_code', $validated['product_code'])->exists()) {
                    $validated['product_code'] = 'PKG' . date('Ymd') . strtoupper(Str::random(6));
                }
            }

            // 创建打包产品
            $product = PkgProduct::create([
                'scenic_spot_id' => $validated['scenic_spot_id'],
                'product_code' => $validated['product_code'],
                'product_name' => $validated['product_name'],
                'stay_days' => $validated['stay_days'] ?? 1,
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 1,
            ]);

            // 创建门票关联
            $bundleItems = $validated['bundle_items'] ?? [];
            foreach ($bundleItems as $item) {
                PkgProductBundleItem::create([
                    'pkg_product_id' => $product->id,
                    'ticket_id' => $item['ticket_id'],
                    'quantity' => $item['quantity'] ?? 1,
                ]);
            }

            // 创建酒店房型关联
            $hotelRoomTypes = $validated['hotel_room_types'] ?? [];
            foreach ($hotelRoomTypes as $hrt) {
                PkgProductHotelRoomType::create([
                    'pkg_product_id' => $product->id,
                    'hotel_id' => $hrt['hotel_id'],
                    'room_type_id' => $hrt['room_type_id'],
                ]);
            }

            // 触发价格更新（异步）
            CalculateProductDailyPricesJob::dispatch($product->id);

            $product->load([
                'scenicSpot',
                'bundleItems.ticket',
                'hotelRoomTypes.hotel',
                'hotelRoomTypes.roomType',
            ]);

            return response()->json([
                'data' => $product,
                'message' => '打包产品创建成功',
            ], 201);
        });
    }

    /**
     * 更新打包产品
     */
    public function update(Request $request, PkgProduct $pkgProduct): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'sometimes|required|exists:scenic_spots,id',
            'product_name' => 'sometimes|required|string|max:100',
            'product_code' => 'sometimes|nullable|string|max:50|unique:pkg_products,product_code,' . $pkgProduct->id,
            'stay_days' => 'nullable|integer|min:1|max:30',
            'description' => 'nullable|string',
            'status' => 'nullable|integer|in:0,1',
            'bundle_items' => 'sometimes|array|min:1',
            'bundle_items.*.ticket_id' => 'required|exists:tickets,id',
            'bundle_items.*.quantity' => 'nullable|integer|min:1',
            'hotel_room_types' => 'sometimes|array|min:1',
            'hotel_room_types.*.hotel_id' => 'required|exists:res_hotels,id',
            'hotel_room_types.*.room_type_id' => 'required|exists:res_room_types,id',
        ]);

        return DB::transaction(function () use ($pkgProduct, $validated) {
            // 更新基本信息
            $updateData = array_filter($validated, function ($key) {
                return !in_array($key, ['bundle_items', 'hotel_room_types']);
            }, ARRAY_FILTER_USE_KEY);
            
            if (!empty($updateData)) {
                $pkgProduct->update($updateData);
            }

            // 更新门票关联
            if (isset($validated['bundle_items'])) {
                // 删除旧的关联
                PkgProductBundleItem::where('pkg_product_id', $pkgProduct->id)->delete();
                // 创建新的关联
                foreach ($validated['bundle_items'] as $item) {
                    PkgProductBundleItem::create([
                        'pkg_product_id' => $pkgProduct->id,
                        'ticket_id' => $item['ticket_id'],
                        'quantity' => $item['quantity'] ?? 1,
                    ]);
                }
            }

            // 更新酒店房型关联
            if (isset($validated['hotel_room_types'])) {
                // 删除旧的关联
                PkgProductHotelRoomType::where('pkg_product_id', $pkgProduct->id)->delete();
                // 创建新的关联
                foreach ($validated['hotel_room_types'] as $hrt) {
                    PkgProductHotelRoomType::create([
                        'pkg_product_id' => $pkgProduct->id,
                        'hotel_id' => $hrt['hotel_id'],
                        'room_type_id' => $hrt['room_type_id'],
                    ]);
                }
            }

            // 如果更新了关联，触发价格更新
            if (isset($validated['bundle_items']) || isset($validated['hotel_room_types'])) {
                CalculateProductDailyPricesJob::dispatch($pkgProduct->id);
            }

            $pkgProduct->load([
                'scenicSpot',
                'bundleItems.ticket',
                'hotelRoomTypes.hotel',
                'hotelRoomTypes.roomType',
            ]);

            return response()->json([
                'data' => $pkgProduct,
                'message' => '打包产品更新成功',
            ]);
        });
    }

    /**
     * 删除打包产品
     */
    public function destroy(PkgProduct $pkgProduct): JsonResponse
    {
        $pkgProduct->delete();

        return response()->json([
            'message' => '打包产品删除成功',
        ]);
    }
}
