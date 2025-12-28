<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    /**
     * 房型列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = RoomType::with(['hotel']);

        if ($request->has('hotel_id')) {
            $query->where('hotel_id', $request->hotel_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // 权限控制：运营只能查看所属资源方下的所有景区下的酒店的房型
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            $query->whereHas('hotel', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        // 如果指定了 hotel_id，通常不需要分页，直接返回所有房型
        if ($request->has('hotel_id') && !$request->has('per_page')) {
            $roomTypes = $query->get();
            return response()->json(['data' => $roomTypes]);
        }

        $roomTypes = $query->paginate($request->get('per_page', 15));

        return response()->json($roomTypes);
    }

    /**
     * 创建房型
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hotel_id' => 'required|exists:hotels,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'max_occupancy' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'external_id' => 'nullable|string|max:255',
            'external_code' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        // 权限控制：运营只能在自己所属资源方下的景区下的酒店中创建房型
        if ($request->user()->isOperator()) {
            $hotel = \App\Models\Hotel::findOrFail($validated['hotel_id']);
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($hotel->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权在该酒店下创建房型',
                ], 403);
            }
        }

        $roomType = RoomType::create($validated);
        $roomType->load('hotel');

        return response()->json([
            'message' => '房型创建成功',
            'data' => $roomType,
        ], 201);
    }

    /**
     * 更新房型
     */
    public function update(Request $request, RoomType $roomType): JsonResponse
    {
        // 权限控制：运营只能更新所属资源方下的所有景区下的酒店的房型
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($roomType->hotel->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权更新该房型',
                ], 403);
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255',
            'max_occupancy' => 'sometimes|required|integer|min:1',
            'description' => 'nullable|string',
            'external_id' => 'nullable|string|max:255',
            'external_code' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        $roomType->update($validated);
        $roomType->load('hotel');

        return response()->json([
            'message' => '房型更新成功',
            'data' => $roomType,
        ]);
    }

    /**
     * 删除房型
     */
    public function destroy(RoomType $roomType): JsonResponse
    {
        // 权限控制：运营只能删除所属资源方下的所有景区下的酒店的房型
        if (request()->user()->isOperator()) {
            $resourceProviderIds = request()->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($roomType->hotel->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权删除该房型',
                ], 403);
            }
        }

        $roomType->delete();

        return response()->json([
            'message' => '房型删除成功',
        ]);
    }
}
