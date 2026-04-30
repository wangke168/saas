<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScenicSpotOtaAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 景区-OTA-账号配置管理（仅超级管理员）
 */
class ScenicSpotOtaAccountController extends Controller
{
    /**
     * 列表（支持按景区、按平台筛选）
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区-OTA-账号配置');
        }

        $query = ScenicSpotOtaAccount::with(['scenicSpot:id,name,code', 'otaPlatform:id,name,code']);

        if ($request->filled('scenic_spot_id')) {
            $query->where('scenic_spot_id', $request->scenic_spot_id);
        }
        if ($request->filled('ota_platform_id')) {
            $query->where('ota_platform_id', $request->ota_platform_id);
        }

        $list = $query->orderBy('scenic_spot_id')->orderBy('ota_platform_id')->paginate(
            $request->input('per_page', 15)
        );

        return response()->json([
            'data' => $list->items(),
            'meta' => [
                'current_page' => $list->currentPage(),
                'last_page' => $list->lastPage(),
                'per_page' => $list->perPage(),
                'total' => $list->total(),
            ],
        ]);
    }

    /**
     * 新增
     */
    public function store(Request $request): JsonResponse
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区-OTA-账号配置');
        }

        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'ota_platform_id' => 'required|exists:ota_platforms,id',
            'account' => 'required|string|max:64',
            'secret_key' => 'nullable|string|max:255',
            'aes_key' => 'nullable|string|max:255',
            'aes_iv' => 'nullable|string|max:255',
        ]);

        // 同一景区同一平台仅允许一条
        $exists = ScenicSpotOtaAccount::where('scenic_spot_id', $validated['scenic_spot_id'])
            ->where('ota_platform_id', $validated['ota_platform_id'])
            ->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => '该景区在该平台已配置账号，请直接编辑',
            ], 422);
        }

        try {
            $row = ScenicSpotOtaAccount::create($validated);
            $row->load(['scenicSpot:id,name,code', 'otaPlatform:id,name,code']);
            return response()->json([
                'success' => true,
                'message' => '创建成功',
                'data' => $row,
            ], 201);
        } catch (\Exception $e) {
            Log::error('景区-OTA-账号创建失败', [
                'input' => $validated,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => '创建失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 更新
     */
    public function update(Request $request, ScenicSpotOtaAccount $scenicSpotOtaAccount): JsonResponse
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区-OTA-账号配置');
        }

        $validated = $request->validate([
            'account' => 'required|string|max:64',
            'secret_key' => 'nullable|string|max:255',
            'aes_key' => 'nullable|string|max:255',
            'aes_iv' => 'nullable|string|max:255',
        ]);

        try {
            $scenicSpotOtaAccount->update($validated);
            $scenicSpotOtaAccount->load(['scenicSpot:id,name,code', 'otaPlatform:id,name,code']);
            return response()->json([
                'success' => true,
                'message' => '更新成功',
                'data' => $scenicSpotOtaAccount,
            ]);
        } catch (\Exception $e) {
            Log::error('景区-OTA-账号更新失败', [
                'id' => $scenicSpotOtaAccount->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => '更新失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 删除
     */
    public function destroy(ScenicSpotOtaAccount $scenicSpotOtaAccount): JsonResponse
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区-OTA-账号配置');
        }

        try {
            $scenicSpotOtaAccount->delete();
            return response()->json([
                'success' => true,
                'message' => '删除成功',
            ]);
        } catch (\Exception $e) {
            Log::error('景区-OTA-账号删除失败', [
                'id' => $scenicSpotOtaAccount->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => '删除失败：' . $e->getMessage(),
            ], 500);
        }
    }
}
