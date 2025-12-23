<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    /**
     * 酒店列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = Hotel::with(['scenicSpot', 'resourceProvider']);

        // 权限控制：运营只能查看自己绑定的景区下的酒店
        if ($request->user()->isOperator()) {
            $scenicSpotIds = $request->user()->scenicSpots->pluck('id');
            $query->whereIn('scenic_spot_id', $scenicSpotIds);
        }

        // 搜索功能
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
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
     * 创建酒店
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'is_connected' => 'boolean',
            'resource_provider_id' => 'nullable|exists:resource_providers,id',
            'is_active' => 'boolean',
        ]);

        // 权限控制：运营只能在自己绑定的景区下创建酒店
        if ($request->user()->isOperator()) {
            $scenicSpotIds = $request->user()->scenicSpots->pluck('id');
            if (! $scenicSpotIds->contains($validated['scenic_spot_id'])) {
                return response()->json([
                    'message' => '无权在该景区下创建酒店',
                ], 403);
            }
        }

        $hotel = Hotel::create($validated);
        $hotel->load(['scenicSpot', 'resourceProvider']);

        return response()->json([
            'message' => '酒店创建成功',
            'data' => $hotel,
        ], 201);
    }

    /**
     * 酒店详情
     */
    public function show(Hotel $hotel): JsonResponse
    {
        $hotel->load(['scenicSpot', 'resourceProvider', 'roomTypes']);
        
        // 权限控制：运营只能查看自己绑定的景区下的酒店
        if (request()->user()->isOperator()) {
            $scenicSpotIds = request()->user()->scenicSpots->pluck('id');
            if (! $scenicSpotIds->contains($hotel->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权查看该酒店',
                ], 403);
            }
        }
        
        return response()->json([
            'data' => $hotel,
        ]);
    }

    /**
     * 更新酒店
     */
    public function update(Request $request, Hotel $hotel): JsonResponse
    {
        // 权限控制：运营只能更新自己绑定的景区下的酒店
        if ($request->user()->isOperator()) {
            $scenicSpotIds = $request->user()->scenicSpots->pluck('id');
            if (! $scenicSpotIds->contains($hotel->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权更新该酒店',
                ], 403);
            }
        }

        $validated = $request->validate([
            'scenic_spot_id' => 'sometimes|required|exists:scenic_spots,id',
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255',
            'address' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'is_connected' => 'sometimes|boolean',
            'resource_provider_id' => 'nullable|exists:resource_providers,id',
            'is_active' => 'sometimes|boolean',
        ]);

        // 如果修改了景区，需要检查权限
        if (isset($validated['scenic_spot_id']) && $request->user()->isOperator()) {
            $scenicSpotIds = $request->user()->scenicSpots->pluck('id');
            if (! $scenicSpotIds->contains($validated['scenic_spot_id'])) {
                return response()->json([
                    'message' => '无权将该酒店移动到该景区',
                ], 403);
            }
        }

        $hotel->update($validated);
        $hotel->load(['scenicSpot', 'resourceProvider']);

        return response()->json([
            'message' => '酒店更新成功',
            'data' => $hotel,
        ]);
    }

    /**
     * 删除酒店
     */
    public function destroy(Hotel $hotel): JsonResponse
    {
        // 权限控制：运营只能删除自己绑定的景区下的酒店
        if (request()->user()->isOperator()) {
            $scenicSpotIds = request()->user()->scenicSpots->pluck('id');
            if (! $scenicSpotIds->contains($hotel->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权删除该酒店',
                ], 403);
            }
        }

        $hotel->delete();

        return response()->json([
            'message' => '酒店删除成功',
        ]);
    }
}
