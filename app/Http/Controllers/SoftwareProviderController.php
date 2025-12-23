<?php

namespace App\Http\Controllers;

use App\Models\SoftwareProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SoftwareProviderController extends Controller
{
    /**
     * 软件商列表（仅超级管理员）
     */
    public function index(Request $request): JsonResponse
    {
        $query = SoftwareProvider::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $providers = $query->paginate($request->get('per_page', 15));

        return response()->json($providers);
    }

    /**
     * 创建软件商（仅超级管理员）
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:software_providers,code',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $provider = SoftwareProvider::create($validated);

        return response()->json([
            'message' => '软件商创建成功',
            'data' => $provider,
        ], 201);
    }

    /**
     * 更新软件商（仅超级管理员）
     */
    public function update(Request $request, SoftwareProvider $softwareProvider): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', 'unique:software_providers,code,' . $softwareProvider->id],
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $softwareProvider->update($validated);

        return response()->json([
            'message' => '软件商更新成功',
            'data' => $softwareProvider,
        ]);
    }

    /**
     * 删除软件商（仅超级管理员）
     */
    public function destroy(SoftwareProvider $softwareProvider): JsonResponse
    {
        $softwareProvider->delete();

        return response()->json([
            'message' => '软件商删除成功',
        ]);
    }
}
