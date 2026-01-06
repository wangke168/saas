<?php

namespace App\Http\Controllers;

use App\Models\Res\ResRoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResRoomTypeController extends Controller
{
    /**
     * 打包房型列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = ResRoomType::with(['hotel.scenicSpot']);

        if ($request->has('hotel_id')) {
            $query->where('hotel_id', $request->hotel_id);
        }

        // 搜索功能
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('external_room_id', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        $roomTypes = $query->paginate($request->get('per_page', 15));

        return response()->json($roomTypes);
    }

    /**
     * 打包房型详情
     */
    public function show(ResRoomType $resRoomType): JsonResponse
    {
        $resRoomType->load(['hotel.scenicSpot']);
        
        return response()->json([
            'data' => $resRoomType,
        ]);
    }

    /**
     * 创建打包房型
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hotel_id' => 'required|exists:res_hotels,id',
            'name' => 'required|string|max:255',
            'external_room_id' => 'nullable|string|max:100',
            'max_occupancy' => 'nullable|integer|min:1',
            'bed_type' => 'nullable|string|max:50',
            'room_area' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $roomType = ResRoomType::create($validated);
        $roomType->load(['hotel.scenicSpot']);

        return response()->json([
            'data' => $roomType,
            'message' => '打包房型创建成功',
        ], 201);
    }

    /**
     * 更新打包房型
     */
    public function update(Request $request, ResRoomType $resRoomType): JsonResponse
    {
        $validated = $request->validate([
            'hotel_id' => 'sometimes|required|exists:res_hotels,id',
            'name' => 'sometimes|required|string|max:255',
            'external_room_id' => 'nullable|string|max:100',
            'max_occupancy' => 'nullable|integer|min:1',
            'bed_type' => 'nullable|string|max:50',
            'room_area' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $resRoomType->update($validated);
        $resRoomType->load(['hotel.scenicSpot']);

        return response()->json([
            'data' => $resRoomType,
            'message' => '打包房型更新成功',
        ]);
    }

    /**
     * 删除打包房型
     */
    public function destroy(ResRoomType $resRoomType): JsonResponse
    {
        $resRoomType->delete();

        return response()->json([
            'message' => '打包房型删除成功',
        ]);
    }
}
