<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OtaPlatform;
use App\Enums\OtaPlatform as OtaPlatformEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OtaPlatformController extends Controller
{
    /**
     * OTA平台列表
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OtaPlatform::class);
        $query = OtaPlatform::query();

        // 搜索
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // 状态筛选
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $platforms = $query->with('config')
            ->orderBy('name')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'data' => $platforms->items(),
            'meta' => [
                'current_page' => $platforms->currentPage(),
                'per_page' => $platforms->perPage(),
                'total' => $platforms->total(),
                'last_page' => $platforms->lastPage(),
            ],
        ]);
    }

    /**
     * OTA平台详情
     */
    public function show(OtaPlatform $otaPlatform): JsonResponse
    {
        $this->authorize('view', $otaPlatform);
        $otaPlatform->load('config');

        return response()->json([
            'data' => $otaPlatform,
        ]);
    }

    /**
     * 创建OTA平台
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', OtaPlatform::class);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:ota_platforms,code',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        // 验证code是否为有效的枚举值
        $validCodes = array_map(fn($case) => $case->value, OtaPlatformEnum::cases());
        if (!in_array($validated['code'], $validCodes)) {
            return response()->json([
                'success' => false,
                'message' => '无效的平台代码，支持的代码：' . implode(', ', $validCodes),
            ], 422);
        }

        try {
            $platform = OtaPlatform::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            return response()->json([
                'success' => true,
                'message' => '创建成功',
                'data' => $platform,
            ], 201);
        } catch (\Exception $e) {
            Log::error('创建OTA平台失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '创建失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 更新OTA平台
     */
    public function update(Request $request, OtaPlatform $otaPlatform): JsonResponse
    {
        $this->authorize('update', $otaPlatform);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50|unique:ota_platforms,code,' . $otaPlatform->id,
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        // 如果更新code，验证是否为有效的枚举值
        if (isset($validated['code'])) {
            $validCodes = array_map(fn($case) => $case->value, OtaPlatformEnum::cases());
            if (!in_array($validated['code'], $validCodes)) {
                return response()->json([
                    'success' => false,
                    'message' => '无效的平台代码，支持的代码：' . implode(', ', $validCodes),
                ], 422);
            }
        }

        try {
            $otaPlatform->update($validated);

            return response()->json([
                'success' => true,
                'message' => '更新成功',
                'data' => $otaPlatform->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('更新OTA平台失败', [
                'platform_id' => $otaPlatform->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '更新失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 删除OTA平台
     */
    public function destroy(OtaPlatform $otaPlatform): JsonResponse
    {
        $this->authorize('delete', $otaPlatform);
        try {
            // 检查是否有关联的产品
            $productCount = $otaPlatform->otaProducts()->count();
            if ($productCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "无法删除：该平台下还有 {$productCount} 个产品绑定",
                ], 422);
            }

            // 检查是否有关联的订单
            $orderCount = $otaPlatform->orders()->count();
            if ($orderCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "无法删除：该平台下还有 {$orderCount} 个订单",
                ], 422);
            }

            $otaPlatform->delete();

            return response()->json([
                'success' => true,
                'message' => '删除成功',
            ]);
        } catch (\Exception $e) {
            Log::error('删除OTA平台失败', [
                'platform_id' => $otaPlatform->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '删除失败：' . $e->getMessage(),
            ], 500);
        }
    }
}
