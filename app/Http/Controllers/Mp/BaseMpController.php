<?php

namespace App\Http\Controllers\Mp;

use App\Http\Controllers\Controller;
use App\Services\Mp\MpAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class BaseMpController extends Controller
{
    protected function resolveBearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    protected function resolvePhone(Request $request, MpAuthService $mpAuthService): ?string
    {
        $token = $this->resolveBearerToken($request);
        if ($token === null) {
            return null;
        }

        return $mpAuthService->resolvePhoneFromToken($token);
    }

    protected function resolveOpenid(Request $request, MpAuthService $mpAuthService): ?string
    {
        $token = $this->resolveBearerToken($request);
        if ($token === null) {
            return null;
        }

        return $mpAuthService->resolveOpenidFromToken($token);
    }

    protected function unauthorized(): JsonResponse
    {
        return response()->json([
            'message' => '未登录或登录已过期',
        ], 401);
    }
}

