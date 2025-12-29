<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OtaConfig;
use App\Models\OtaPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OtaConfigController extends Controller
{
    /**
     * 获取OTA平台的配置
     */
    public function show(OtaPlatform $otaPlatform): JsonResponse
    {
        // 仅超级管理员可以管理配置
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理OTA配置');
        }
        $config = $otaPlatform->config;

        // 前端需要这些字段来编辑，所以临时显示被隐藏的敏感字段
        if ($config) {
            $config->makeVisible(['secret_key', 'aes_key', 'aes_iv', 'rsa_private_key']);
        }

        return response()->json([
            'data' => $config,
        ]);
    }

    /**
     * 创建或更新OTA配置
     */
    public function store(Request $request, OtaPlatform $otaPlatform): JsonResponse
    {
        // 仅超级管理员可以管理配置
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理OTA配置');
        }
        
        $validated = $request->validate([
            'account' => 'required|string|max:255',
            'secret_key' => 'required|string|max:255',
            'aes_key' => 'nullable|string|max:255',
            'aes_iv' => 'nullable|string|max:255',
            'rsa_private_key' => 'nullable|string',
            'rsa_public_key' => 'nullable|string',
            'api_url' => 'required|url|max:500',
            'callback_url' => 'nullable|url|max:500',
            'environment' => 'required|string|in:sandbox,production',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            // 如果已存在配置，则更新；否则创建
            $config = $otaPlatform->config;

            if ($config) {
                $config->update($validated);
            } else {
                $validated['ota_platform_id'] = $otaPlatform->id;
                $config = OtaConfig::create($validated);
            }

            return response()->json([
                'success' => true,
                'message' => $config->wasRecentlyCreated ? '创建成功' : '更新成功',
                'data' => $config->fresh(),
            ], $config->wasRecentlyCreated ? 201 : 200);
        } catch (\Exception $e) {
            Log::error('保存OTA配置失败', [
                'platform_id' => $otaPlatform->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '保存失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 更新OTA配置
     */
    public function update(Request $request, OtaConfig $otaConfig): JsonResponse
    {
        // 仅超级管理员可以管理配置
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理OTA配置');
        }
        
        $validated = $request->validate([
            'account' => 'sometimes|string|max:255',
            'secret_key' => 'sometimes|string|max:255',
            'aes_key' => 'nullable|string|max:255',
            'aes_iv' => 'nullable|string|max:255',
            'rsa_private_key' => 'nullable|string',
            'rsa_public_key' => 'nullable|string',
            'api_url' => 'sometimes|url|max:500',
            'callback_url' => 'nullable|url|max:500',
            'environment' => 'sometimes|string|in:sandbox,production',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            $otaConfig->update($validated);

            return response()->json([
                'success' => true,
                'message' => '更新成功',
                'data' => $otaConfig->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('更新OTA配置失败', [
                'config_id' => $otaConfig->id,
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
     * 删除OTA配置
     */
    public function destroy(OtaConfig $otaConfig): JsonResponse
    {
        // 仅超级管理员可以管理配置
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403, '仅超级管理员可以管理OTA配置');
        }
        
        try {
            $otaConfig->delete();

            return response()->json([
                'success' => true,
                'message' => '删除成功',
            ]);
        } catch (\Exception $e) {
            Log::error('删除OTA配置失败', [
                'config_id' => $otaConfig->id,
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
