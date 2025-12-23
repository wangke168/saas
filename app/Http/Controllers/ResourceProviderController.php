<?php

namespace App\Http\Controllers;

use App\Models\ResourceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceProviderController extends Controller
{
    /**
     * 资源方列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = ResourceProvider::query();

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
     * 资源方详情
     */
    public function show(ResourceProvider $resourceProvider): JsonResponse
    {
        $resourceProvider->load('config');
        
        return response()->json([
            'data' => $resourceProvider,
        ]);
    }
}

