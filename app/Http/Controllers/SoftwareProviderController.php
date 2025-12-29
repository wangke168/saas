<?php

namespace App\Http\Controllers;

use App\Models\SoftwareProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SoftwareProviderController extends Controller
{
    /**
     * 系统服务商列表
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SoftwareProvider::class);
        $query = SoftwareProvider::withCount('scenicSpots');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // 如果只需要列表（不分页），可以添加 all 参数
        if ($request->has('all') && $request->boolean('all')) {
            $providers = $query->where('is_active', true)->get();
            return response()->json([
                'data' => $providers,
            ]);
        }

        $providers = $query->orderBy('created_at', 'desc')
                          ->paginate($request->get('per_page', 15));

        return response()->json($providers);
    }

    /**
     * 系统服务商详情
     */
    public function show(SoftwareProvider $softwareProvider): JsonResponse
    {
        $this->authorize('view', $softwareProvider);
        $softwareProvider->load(['scenicSpots', 'resourceConfigs']);
        
        return response()->json([
            'data' => $softwareProvider,
        ]);
    }

    /**
     * 创建系统服务商
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SoftwareProvider::class);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:software_providers,code',
            'description' => 'nullable|string',
            'api_type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $provider = SoftwareProvider::create($validated);

        return response()->json([
            'message' => '系统服务商创建成功',
            'data' => $provider,
        ], 201);
    }

    /**
     * 更新系统服务商
     */
    public function update(Request $request, SoftwareProvider $softwareProvider): JsonResponse
    {
        $this->authorize('update', $softwareProvider);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:software_providers,code,' . $softwareProvider->id,
            'description' => 'nullable|string',
            'api_type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $softwareProvider->update($validated);

        return response()->json([
            'message' => '系统服务商更新成功',
            'data' => $softwareProvider,
        ]);
    }

    /**
     * 删除系统服务商
     */
    public function destroy(SoftwareProvider $softwareProvider): JsonResponse
    {
        $this->authorize('delete', $softwareProvider);
        // 检查是否有关联的景区
        if ($softwareProvider->scenicSpots()->count() > 0) {
            return response()->json([
                'message' => '无法删除：该系统服务商下还有关联的景区',
            ], 422);
        }

        $softwareProvider->delete();

        return response()->json([
            'message' => '系统服务商删除成功',
        ]);
    }
}


    /**
     * 创建系统服务商
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SoftwareProvider::class);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:software_providers,code',
            'description' => 'nullable|string',
            'api_type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $provider = SoftwareProvider::create($validated);

        return response()->json([
            'message' => '系统服务商创建成功',
            'data' => $provider,
        ], 201);
    }

    /**
     * 更新系统服务商
     */
    public function update(Request $request, SoftwareProvider $softwareProvider): JsonResponse
    {
        $this->authorize('update', $softwareProvider);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:software_providers,code,' . $softwareProvider->id,
            'description' => 'nullable|string',
            'api_type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $softwareProvider->update($validated);

        return response()->json([
            'message' => '系统服务商更新成功',
            'data' => $softwareProvider,
        ]);
    }

    /**
     * 删除系统服务商
     */
    public function destroy(SoftwareProvider $softwareProvider): JsonResponse
    {
        $this->authorize('delete', $softwareProvider);
        // 检查是否有关联的景区
        if ($softwareProvider->scenicSpots()->count() > 0) {
            return response()->json([
                'message' => '无法删除：该系统服务商下还有关联的景区',
            ], 422);
        }

        $softwareProvider->delete();

        return response()->json([
            'message' => '系统服务商删除成功',
        ]);
    }
}


    /**
     * 创建系统服务商
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SoftwareProvider::class);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:software_providers,code',
            'description' => 'nullable|string',
            'api_type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $provider = SoftwareProvider::create($validated);

        return response()->json([
            'message' => '系统服务商创建成功',
            'data' => $provider,
        ], 201);
    }

    /**
     * 更新系统服务商
     */
    public function update(Request $request, SoftwareProvider $softwareProvider): JsonResponse
    {
        $this->authorize('update', $softwareProvider);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:software_providers,code,' . $softwareProvider->id,
            'description' => 'nullable|string',
            'api_type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $softwareProvider->update($validated);

        return response()->json([
            'message' => '系统服务商更新成功',
            'data' => $softwareProvider,
        ]);
    }

    /**
     * 删除系统服务商
     */
    public function destroy(SoftwareProvider $softwareProvider): JsonResponse
    {
        $this->authorize('delete', $softwareProvider);
        // 检查是否有关联的景区
        if ($softwareProvider->scenicSpots()->count() > 0) {
            return response()->json([
                'message' => '无法删除：该系统服务商下还有关联的景区',
            ], 422);
        }

        $softwareProvider->delete();

        return response()->json([
            'message' => '系统服务商删除成功',
        ]);
    }
}


    /**
     * 创建系统服务商
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SoftwareProvider::class);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:software_providers,code',
            'description' => 'nullable|string',
            'api_type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $provider = SoftwareProvider::create($validated);

        return response()->json([
            'message' => '系统服务商创建成功',
            'data' => $provider,
        ], 201);
    }

    /**
     * 更新系统服务商
     */
    public function update(Request $request, SoftwareProvider $softwareProvider): JsonResponse
    {
        $this->authorize('update', $softwareProvider);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:software_providers,code,' . $softwareProvider->id,
            'description' => 'nullable|string',
            'api_type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $softwareProvider->update($validated);

        return response()->json([
            'message' => '系统服务商更新成功',
            'data' => $softwareProvider,
        ]);
    }

    /**
     * 删除系统服务商
     */
    public function destroy(SoftwareProvider $softwareProvider): JsonResponse
    {
        $this->authorize('delete', $softwareProvider);
        // 检查是否有关联的景区
        if ($softwareProvider->scenicSpots()->count() > 0) {
            return response()->json([
                'message' => '无法删除：该系统服务商下还有关联的景区',
            ], 422);
        }

        $softwareProvider->delete();

        return response()->json([
            'message' => '系统服务商删除成功',
        ]);
    }
}
