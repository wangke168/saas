<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OtaPlatform;
use App\Models\ScenicSpotOtaAutoAcceptConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ScenicSpotOtaAutoAcceptConfigController extends Controller
{
    /**
     * 列表（按景区筛选）
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区-OTA-自动接单配置');
        }

        $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
        ]);

        $list = ScenicSpotOtaAutoAcceptConfig::with(['otaPlatform:id,name,code'])
            ->where('scenic_spot_id', $request->scenic_spot_id)
            ->orderBy('ota_platform_id')
            ->get();

        return response()->json([
            'data' => $list,
        ]);
    }

    /**
     * 创建或更新配置（upsert逻辑：景区+平台唯一）
     */
    public function save(Request $request): JsonResponse
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区-OTA-自动接单配置');
        }

        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'ota_platform_id' => 'required|exists:ota_platforms,id',
            'auto_accept_when_sufficient' => 'required|boolean',
            'auto_accept_stock_buffer' => 'required|integer|min:0',
            'is_active' => 'required|boolean',
        ]);

        try {
            // 查询是否已存在
            $existing = ScenicSpotOtaAutoAcceptConfig::where('scenic_spot_id', $validated['scenic_spot_id'])
                ->where('ota_platform_id', $validated['ota_platform_id'])
                ->first();

            if ($existing) {
                // 更新
                $existing->update([
                    'auto_accept_when_sufficient' => $validated['auto_accept_when_sufficient'],
                    'auto_accept_stock_buffer' => $validated['auto_accept_stock_buffer'],
                    'is_active' => $validated['is_active'],
                ]);
                $config = $existing;
            } else {
                // 创建
                $config = ScenicSpotOtaAutoAcceptConfig::create($validated);
            }

            // 清除缓存
            $this->clearCache($validated['scenic_spot_id'], $validated['ota_platform_id']);

            $config->load(['otaPlatform:id,name,code']);

            return response()->json([
                'success' => true,
                'message' => $existing ? '更新成功' : '创建成功',
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            Log::error('景区-OTA-自动接单配置保存失败', [
                'input' => $validated,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => '保存失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 删除
     */
    public function destroy(Request $request, ScenicSpotOtaAutoAcceptConfig $config): JsonResponse
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区-OTA-自动接单配置');
        }

        try {
            $scenicSpotId = $config->scenic_spot_id;
            $otaPlatformId = $config->ota_platform_id;

            $config->delete();

            // 清除缓存
            $this->clearCache($scenicSpotId, $otaPlatformId);

            return response()->json([
                'success' => true,
                'message' => '删除成功',
            ]);
        } catch (\Exception $e) {
            Log::error('景区-OTA-自动接单配置删除失败', [
                'id' => $config->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => '删除失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 清除指定景区+平台的缓存
     */
    protected function clearCache(int $scenicSpotId, int $otaPlatformId): void
    {
        $platform = OtaPlatform::find($otaPlatformId);
        if ($platform) {
            $cacheKey = "ota_auto_accept:{$scenicSpotId}:{$platform->code->value}";
            Cache::forget($cacheKey);
        }
    }
}
