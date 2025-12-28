<?php

namespace App\Http\Controllers;

use App\Models\ScenicSpot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScenicSpotController extends Controller
{
    /**
     * 景区列表（仅超级管理员）
     */
    public function index(Request $request): JsonResponse
    {
        $query = ScenicSpot::with(['softwareProvider', 'resourceProviders']);

        // 搜索功能
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        $scenicSpots = $query->paginate($request->get('per_page', 15));

        return response()->json($scenicSpots);
    }

    /**
     * 创建景区（仅超级管理员）
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|unique:scenic_spots,code', // 改为可空，自动生成
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'contact_phone' => 'nullable|string',
            'software_provider_id' => 'nullable|exists:software_providers,id',
            'resource_provider_id' => 'nullable|exists:resource_providers,id',
            'is_active' => 'boolean',
        ]);

        $scenicSpot = ScenicSpot::create($validated);
        $scenicSpot->load(['softwareProvider', 'resourceProviders']);

        return response()->json([
            'message' => '景区创建成功',
            'data' => $scenicSpot,
        ], 201);
    }

    /**
     * 景区详情（仅超级管理员）
     */
    public function show(ScenicSpot $scenicSpot): JsonResponse
    {
        $scenicSpot->load(['softwareProvider', 'resourceProviders', 'hotels', 'products']);
        
        return response()->json([
            'data' => $scenicSpot,
        ]);
    }

    /**
     * 更新景区（仅超级管理员）
     */
    public function update(Request $request, ScenicSpot $scenicSpot): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => ['sometimes', 'nullable', 'string', 'unique:scenic_spots,code,' . $scenicSpot->id], // 改为可空
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'software_provider_id' => 'nullable|exists:software_providers,id',
            'resource_provider_id' => 'nullable|exists:resource_providers,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $scenicSpot->update($validated);
        $scenicSpot->load(['softwareProvider', 'resourceProviders']);

        return response()->json([
            'message' => '景区更新成功',
            'data' => $scenicSpot,
        ]);
    }

    /**
     * 删除景区（仅超级管理员）
     */
    public function destroy(ScenicSpot $scenicSpot): JsonResponse
    {
        $scenicSpot->delete();

        return response()->json([
            'message' => '景区删除成功',
        ]);
    }
}
