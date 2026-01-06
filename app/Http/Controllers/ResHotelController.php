<?php

namespace App\Http\Controllers;

use App\Models\Res\ResHotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResHotelController extends Controller
{
    /**
     * 打包酒店列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = ResHotel::with(['scenicSpot', 'softwareProvider', 'roomTypes']);

        // 权限控制：运营只能查看所属资源方下的所有景区下的酒店
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
                  ->orWhere('external_hotel_id', 'like', "%{$search}%");
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

        $hotels = $query->paginate($request->get('per_page', 15));

        return response()->json($hotels);
    }

    /**
     * 打包酒店详情
     */
    public function show(ResHotel $resHotel): JsonResponse
    {
        $resHotel->load(['scenicSpot', 'softwareProvider', 'roomTypes']);
        
        return response()->json([
            'data' => $resHotel,
        ]);
    }

    /**
     * 创建打包酒店
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'software_provider_id' => 'nullable|exists:software_providers,id',
            'name' => 'required|string|max:255',
            'external_hotel_id' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'contact_phone' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $hotel = ResHotel::create($validated);
        $hotel->load(['scenicSpot', 'softwareProvider']);

        return response()->json([
            'data' => $hotel,
            'message' => '打包酒店创建成功',
        ], 201);
    }

    /**
     * 更新打包酒店
     */
    public function update(Request $request, ResHotel $resHotel): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'sometimes|required|exists:scenic_spots,id',
            'software_provider_id' => 'nullable|exists:software_providers,id',
            'name' => 'sometimes|required|string|max:255',
            'external_hotel_id' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'contact_phone' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $resHotel->update($validated);
        $resHotel->load(['scenicSpot', 'softwareProvider']);

        return response()->json([
            'data' => $resHotel,
            'message' => '打包酒店更新成功',
        ]);
    }

    /**
     * 删除打包酒店
     */
    public function destroy(ResHotel $resHotel): JsonResponse
    {
        $resHotel->delete();

        return response()->json([
            'message' => '打包酒店删除成功',
        ]);
    }
}
