<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * @group 认证管理
 *
 * 用户登录、登出和获取当前用户信息
 */
class AuthController extends Controller
{
    /**
     * 用户登录
     *
     * @bodyParam email string required 邮箱地址
     * @bodyParam password string required 密码
     *
     * @response 200 {
     *   "token": "1|xxxxxxxxxxxx",
     *   "user": {
     *     "id": 1,
     *     "name": "管理员",
     *     "email": "admin@example.com"
     *   }
     * }
     *
     * @response 422 {
     *   "success": false,
     *   "message": "数据验证失败",
     *   "errors": {
     *     "email": ["邮箱格式不正确"]
     *   }
     * }
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // 先查找用户
        $user = User::where('email', $request->email)->first();

        // 检查用户是否存在
        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['邮箱或密码错误'],
            ]);
        }

        // 检查账号是否被禁用
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['账号已被禁用，请联系管理员'],
            ]);
        }

        // 验证密码 - 使用 Hash::check 直接验证
        if (! \Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['邮箱或密码错误'],
            ]);
        }

        // 手动登录用户（因为使用了 Hash::check，需要手动设置）
        Auth::login($user);

        $token = $user->createToken('api-token')->plainTextToken;

        // 重新加载用户数据（避免缓存问题）
        $user->refresh();

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value ?? 'admin',
                'role_label' => $user->role?->label() ?? '超级管理员',
                'is_active' => $user->is_active,
            ],
        ]);
    }

    /**
     * 用户登出
     *
     * @authenticated
     *
     * @response 200 {
     *   "message": "登出成功"
     * }
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => '登出成功']);
    }

    /**
     * 获取当前用户信息
     * 
     * @authenticated
     * 
     * @response 200 {
     *   "user": {
     *     "id": 1,
     *     "name": "管理员",
     *     "email": "admin@example.com",
     *     "role": "admin"
     *   }
     * }
     */
    public function user(Request $request)
    {
        $user = $request->user();
        $user->load(['resourceProviders.scenicSpots']); // 加载资源方及其景区
        
        // 构建显示名称
        $displayName = $user->name;
        if ($user->isOperator() && $user->resourceProviders->isNotEmpty()) {
            $resourceProviderNames = $user->resourceProviders->pluck('name')->join('、');
            $displayName = "{$resourceProviderNames}-{$user->name}";
        }
        
        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'display_name' => $displayName, // 新增显示名称
                'email' => $user->email,
                'role' => $user->role?->value ?? 'admin',
                'role_label' => $user->role?->label() ?? '超级管理员',
                'is_active' => $user->is_active,
                'resource_providers' => $user->resourceProviders->map(fn($rp) => [
                    'id' => $rp->id,
                    'name' => $rp->name,
                    'code' => $rp->code,
                    'scenic_spots' => $rp->scenicSpots->map(fn($spot) => [
                        'id' => $spot->id,
                        'name' => $spot->name,
                        'code' => $spot->code,
                    ]),
                ]),
                // 保留scenic_spots用于兼容（但不再使用）
                // 通过 resourceProviders 获取景区
                'scenic_spots' => $user->resourceProviders->flatMap(function($rp) {
                    return $rp->scenicSpots->map(fn($spot) => [
                        'id' => $spot->id,
                        'name' => $spot->name,
                        'code' => $spot->code,
                    ]);
                })->unique('id')->values(),
            ],
        ]);
    }
}