<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScenicSpotOrderPushConfig;
use App\Services\ExternalOrder\ExternalOrderPushConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScenicSpotOrderPushConfigController extends Controller
{
    public function __construct(
        private readonly ExternalOrderPushConfigService $configService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区订单推送配置');
        }

        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
        ]);

        $config = ScenicSpotOrderPushConfig::query()
            ->where('scenic_spot_id', $validated['scenic_spot_id'])
            ->first();

        return response()->json([
            'data' => $config ?? [
                'scenic_spot_id' => (int) $validated['scenic_spot_id'],
                'enabled' => false,
                'remark' => null,
            ],
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        if (! auth()->user() || ! auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理景区订单推送配置');
        }

        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'enabled' => 'required|boolean',
            'remark' => 'nullable|string|max:255',
        ]);

        try {
            $config = ScenicSpotOrderPushConfig::query()->updateOrCreate(
                ['scenic_spot_id' => $validated['scenic_spot_id']],
                [
                    'enabled' => $validated['enabled'],
                    'remark' => $validated['remark'] ?? null,
                ],
            );

            $this->configService->clearCache((int) $validated['scenic_spot_id']);

            return response()->json([
                'success' => true,
                'message' => '保存成功',
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            Log::error('景区订单推送配置保存失败', [
                'input' => $validated,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '保存失败：'.$e->getMessage(),
            ], 500);
        }
    }
}
