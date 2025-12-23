<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OtaPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OtaPlatformController extends Controller
{
    /**
     * OTA平台列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = OtaPlatform::query();

        // 只返回启用的平台
        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        $platforms = $query->orderBy('name')->get();

        return response()->json([
            'data' => $platforms,
        ]);
    }

    /**
     * OTA平台详情
     */
    public function show(OtaPlatform $otaPlatform): JsonResponse
    {
        $otaPlatform->load('config');

        return response()->json([
            'data' => $otaPlatform,
        ]);
    }
}

