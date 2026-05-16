<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OtaPlatform;
use App\Models\ScenicSpotOtaInventoryPushConfig;
use App\Services\OTA\OtaInventoryPushConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScenicSpotOtaInventoryPushConfigController extends Controller
{
    public function __construct(
        protected OtaInventoryPushConfigService $inventoryPushConfigService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区-OTA-库存推送配置');
        }

        $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
        ]);

        $list = ScenicSpotOtaInventoryPushConfig::with(['otaPlatform:id,name,code'])
            ->where('scenic_spot_id', $request->scenic_spot_id)
            ->orderBy('ota_platform_id')
            ->get();

        return response()->json([
            'data' => $list,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区-OTA-库存推送配置');
        }

        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'ota_platform_id' => 'required|exists:ota_platforms,id',
            'push_zero_threshold' => 'required|integer|min:0|max:9999',
            'is_active' => 'required|boolean',
        ]);

        try {
            $existing = ScenicSpotOtaInventoryPushConfig::where('scenic_spot_id', $validated['scenic_spot_id'])
                ->where('ota_platform_id', $validated['ota_platform_id'])
                ->first();

            if ($existing) {
                $existing->update([
                    'push_zero_threshold' => $validated['push_zero_threshold'],
                    'is_active' => $validated['is_active'],
                ]);
                $config = $existing;
            } else {
                $config = ScenicSpotOtaInventoryPushConfig::create($validated);
            }

            $this->inventoryPushConfigService->clearCache(
                $validated['scenic_spot_id'],
                $validated['ota_platform_id']
            );

            $config->load(['otaPlatform:id,name,code']);

            return response()->json([
                'success' => true,
                'message' => $existing ? '更新成功' : '创建成功',
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            Log::error('景区-OTA-库存推送配置保存失败', [
                'input' => $validated,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '保存失败：'.$e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, ScenicSpotOtaInventoryPushConfig $config): JsonResponse
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区-OTA-库存推送配置');
        }

        try {
            $scenicSpotId = $config->scenic_spot_id;
            $otaPlatformId = $config->ota_platform_id;

            $config->delete();

            $this->inventoryPushConfigService->clearCache($scenicSpotId, $otaPlatformId);

            return response()->json([
                'success' => true,
                'message' => '删除成功',
            ]);
        } catch (\Exception $e) {
            Log::error('景区-OTA-库存推送配置删除失败', [
                'id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '删除失败：'.$e->getMessage(),
            ], 500);
        }
    }
}
