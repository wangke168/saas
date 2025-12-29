<?php

namespace App\Http\Controllers;

use App\Models\ResourceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceProviderController extends Controller
{
    /**
     * 资源方列表（仅超级管理员）
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ResourceProvider::class);
        
        $query = ResourceProvider::with(['users', 'scenicSpots']);
        
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
        
        $resourceProviders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));
        
        return response()->json($resourceProviders);
    }

    /**
     * 资源方详情（仅超级管理员）
     */
    public function show(ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('view', $resourceProvider);
        
        $resourceProvider->load(['users', 'scenicSpots']);
        
        return response()->json([
            'data' => $resourceProvider,
        ]);
    }

    /**
     * 创建资源方（仅超级管理员）
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ResourceProvider::class);
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|unique:resource_providers,code',
            'description' => 'nullable|string',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ]);
        
        // 如果没有提供 code，自动生成
        if (empty($validated['code'])) {
            $validated['code'] = ResourceProvider::generateUniqueCode();
        }
        
        $resourceProvider = ResourceProvider::create($validated);
        $resourceProvider->load(['users', 'scenicSpots']);
        
        return response()->json([
            'message' => '资源方创建成功',
            'data' => $resourceProvider,
        ], 201);
    }

    /**
     * 更新资源方（仅超级管理员）
     */
    public function update(Request $request, ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('update', $resourceProvider);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => ['sometimes', 'required', 'string', 'unique:resource_providers,code,' . $resourceProvider->id],
            'description' => 'nullable|string',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:255',
            'is_active' => 'sometimes|boolean',
        ]);
        
        $resourceProvider->update($validated);
        $resourceProvider->load(['users', 'scenicSpots']);
        
        return response()->json([
            'message' => '资源方更新成功',
            'data' => $resourceProvider,
        ]);
    }

    /**
     * 删除资源方（仅超级管理员）
     */
    public function destroy(ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('delete', $resourceProvider);
        
        $resourceProvider->delete();
        
        return response()->json([
            'message' => '资源方删除成功',
        ]);
    }

    /**
     * 关联景区（仅超级管理员）
     */
    public function attachScenicSpots(Request $request, ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('update', $resourceProvider);
        
        $validated = $request->validate([
            'scenic_spot_ids' => 'required|array',
            'scenic_spot_ids.*' => 'exists:scenic_spots,id',
        ]);
        
        $resourceProvider->scenicSpots()->sync($validated['scenic_spot_ids']);
        
        // 同时更新景区的 resource_provider_id 字段（用于快速查询）
        foreach ($validated['scenic_spot_ids'] as $scenicSpotId) {
            \App\Models\ScenicSpot::where('id', $scenicSpotId)
                ->update(['resource_provider_id' => $resourceProvider->id]);
        }
        
        $resourceProvider->load('scenicSpots');
        
        return response()->json([
            'message' => '景区关联成功',
            'data' => $resourceProvider,
        ]);
    }
}

            'contact_email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ]);
        
        // 如果没有提供 code，自动生成
        if (empty($validated['code'])) {
            $validated['code'] = ResourceProvider::generateUniqueCode();
        }
        
        $resourceProvider = ResourceProvider::create($validated);
        $resourceProvider->load(['users', 'scenicSpots']);
        
        return response()->json([
            'message' => '资源方创建成功',
            'data' => $resourceProvider,
        ], 201);
    }

    /**
     * 更新资源方（仅超级管理员）
     */
    public function update(Request $request, ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('update', $resourceProvider);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => ['sometimes', 'required', 'string', 'unique:resource_providers,code,' . $resourceProvider->id],
            'description' => 'nullable|string',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:255',
            'is_active' => 'sometimes|boolean',
        ]);
        
        $resourceProvider->update($validated);
        $resourceProvider->load(['users', 'scenicSpots']);
        
        return response()->json([
            'message' => '资源方更新成功',
            'data' => $resourceProvider,
        ]);
    }

    /**
     * 删除资源方（仅超级管理员）
     */
    public function destroy(ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('delete', $resourceProvider);
        
        $resourceProvider->delete();
        
        return response()->json([
            'message' => '资源方删除成功',
        ]);
    }

    /**
     * 关联景区（仅超级管理员）
     */
    public function attachScenicSpots(Request $request, ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('update', $resourceProvider);
        
        $validated = $request->validate([
            'scenic_spot_ids' => 'required|array',
            'scenic_spot_ids.*' => 'exists:scenic_spots,id',
        ]);
        
        $resourceProvider->scenicSpots()->sync($validated['scenic_spot_ids']);
        
        // 同时更新景区的 resource_provider_id 字段（用于快速查询）
        foreach ($validated['scenic_spot_ids'] as $scenicSpotId) {
            \App\Models\ScenicSpot::where('id', $scenicSpotId)
                ->update(['resource_provider_id' => $resourceProvider->id]);
        }
        
        $resourceProvider->load('scenicSpots');
        
        return response()->json([
            'message' => '景区关联成功',
            'data' => $resourceProvider,
        ]);
    }
}

            'contact_email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ]);
        
        // 如果没有提供 code，自动生成
        if (empty($validated['code'])) {
            $validated['code'] = ResourceProvider::generateUniqueCode();
        }
        
        $resourceProvider = ResourceProvider::create($validated);
        $resourceProvider->load(['users', 'scenicSpots']);
        
        return response()->json([
            'message' => '资源方创建成功',
            'data' => $resourceProvider,
        ], 201);
    }

    /**
     * 更新资源方（仅超级管理员）
     */
    public function update(Request $request, ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('update', $resourceProvider);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => ['sometimes', 'required', 'string', 'unique:resource_providers,code,' . $resourceProvider->id],
            'description' => 'nullable|string',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:255',
            'is_active' => 'sometimes|boolean',
        ]);
        
        $resourceProvider->update($validated);
        $resourceProvider->load(['users', 'scenicSpots']);
        
        return response()->json([
            'message' => '资源方更新成功',
            'data' => $resourceProvider,
        ]);
    }

    /**
     * 删除资源方（仅超级管理员）
     */
    public function destroy(ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('delete', $resourceProvider);
        
        $resourceProvider->delete();
        
        return response()->json([
            'message' => '资源方删除成功',
        ]);
    }

    /**
     * 关联景区（仅超级管理员）
     */
    public function attachScenicSpots(Request $request, ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('update', $resourceProvider);
        
        $validated = $request->validate([
            'scenic_spot_ids' => 'required|array',
            'scenic_spot_ids.*' => 'exists:scenic_spots,id',
        ]);
        
        $resourceProvider->scenicSpots()->sync($validated['scenic_spot_ids']);
        
        // 同时更新景区的 resource_provider_id 字段（用于快速查询）
        foreach ($validated['scenic_spot_ids'] as $scenicSpotId) {
            \App\Models\ScenicSpot::where('id', $scenicSpotId)
                ->update(['resource_provider_id' => $resourceProvider->id]);
        }
        
        $resourceProvider->load('scenicSpots');
        
        return response()->json([
            'message' => '景区关联成功',
            'data' => $resourceProvider,
        ]);
    }
}

            'contact_email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ]);
        
        // 如果没有提供 code，自动生成
        if (empty($validated['code'])) {
            $validated['code'] = ResourceProvider::generateUniqueCode();
        }
        
        $resourceProvider = ResourceProvider::create($validated);
        $resourceProvider->load(['users', 'scenicSpots']);
        
        return response()->json([
            'message' => '资源方创建成功',
            'data' => $resourceProvider,
        ], 201);
    }

    /**
     * 更新资源方（仅超级管理员）
     */
    public function update(Request $request, ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('update', $resourceProvider);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => ['sometimes', 'required', 'string', 'unique:resource_providers,code,' . $resourceProvider->id],
            'description' => 'nullable|string',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'contact_email' => 'nullable|email|max:255',
            'is_active' => 'sometimes|boolean',
        ]);
        
        $resourceProvider->update($validated);
        $resourceProvider->load(['users', 'scenicSpots']);
        
        return response()->json([
            'message' => '资源方更新成功',
            'data' => $resourceProvider,
        ]);
    }

    /**
     * 删除资源方（仅超级管理员）
     */
    public function destroy(ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('delete', $resourceProvider);
        
        $resourceProvider->delete();
        
        return response()->json([
            'message' => '资源方删除成功',
        ]);
    }

    /**
     * 关联景区（仅超级管理员）
     */
    public function attachScenicSpots(Request $request, ResourceProvider $resourceProvider): JsonResponse
    {
        $this->authorize('update', $resourceProvider);
        
        $validated = $request->validate([
            'scenic_spot_ids' => 'required|array',
            'scenic_spot_ids.*' => 'exists:scenic_spots,id',
        ]);
        
        $resourceProvider->scenicSpots()->sync($validated['scenic_spot_ids']);
        
        // 同时更新景区的 resource_provider_id 字段（用于快速查询）
        foreach ($validated['scenic_spot_ids'] as $scenicSpotId) {
            \App\Models\ScenicSpot::where('id', $scenicSpotId)
                ->update(['resource_provider_id' => $resourceProvider->id]);
        }
        
        $resourceProvider->load('scenicSpots');
        
        return response()->json([
            'message' => '景区关联成功',
            'data' => $resourceProvider,
        ]);
    }
}