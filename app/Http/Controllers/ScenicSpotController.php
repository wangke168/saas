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
        $query = ScenicSpot::with(['softwareProviders', 'resourceProviders']);

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
            'software_provider_ids' => 'nullable|array',
            'software_provider_ids.*' => 'exists:software_providers,id',
            'resource_provider_id' => 'nullable|exists:resource_providers,id',
            'is_active' => 'boolean',
        ]);

        // 提取服务商ID（如果提供）
        $softwareProviderIds = $validated['software_provider_ids'] ?? [];
        unset($validated['software_provider_ids']);

        $scenicSpot = ScenicSpot::create($validated);
        
        // 同步多对多关系
        if (!empty($softwareProviderIds)) {
            $scenicSpot->softwareProviders()->sync($softwareProviderIds);
        }
        
        $scenicSpot->load(['softwareProviders', 'resourceProviders']);

        return response()->json([
            'message' => '景区创建成功',
            'data' => $scenicSpot,
        ], 201);
    }

    /**
     * 景区详情
     * 超级管理员可以查看所有景区，运营账号只能查看其有权限的景区
     */
    public function show(Request $request, ScenicSpot $scenicSpot): JsonResponse
    {
        $user = $request->user();
        
        // 权限检查：运营账号只能查看其有权限的景区
        if (!$user->isAdmin()) {
            $accessibleScenicSpotIds = $user->accessibleScenicSpots()->pluck('id');
            if (!$accessibleScenicSpotIds->contains($scenicSpot->id)) {
                abort(403, '无权查看该景区');
            }
        }
        
        $scenicSpot->load(['softwareProviders', 'resourceProviders', 'hotels', 'products']);
        
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
            'software_provider_ids' => 'nullable|array',
            'software_provider_ids.*' => 'exists:software_providers,id',
            'resource_provider_id' => 'nullable|exists:resource_providers,id',
            'is_active' => 'sometimes|boolean',
        ]);

        // 提取服务商ID（如果提供）
        $softwareProviderIds = $validated['software_provider_ids'] ?? null;
        unset($validated['software_provider_ids']);

        $scenicSpot->update($validated);
        
        // 如果提供了服务商ID，同步多对多关系
        if ($softwareProviderIds !== null) {
            $scenicSpot->softwareProviders()->sync($softwareProviderIds);
        }
        
        $scenicSpot->load(['softwareProviders', 'resourceProviders']);

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
