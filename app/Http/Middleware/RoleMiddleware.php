<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles 允许的角色，可以用逗号分隔多个角色，如 'admin,operator'
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => '未认证',
            ], 401);
        }

        // 如果用户是超级管理员，直接通过
        if ($user->isAdmin()) {
            return $next($request);
        }

        // 处理多个角色参数（Laravel 会将逗号分隔的参数作为多个参数传递）
        // 如果只有一个参数且包含逗号，需要手动分割
        $allowedRoles = [];
        foreach ($roles as $role) {
            // 如果参数包含逗号，分割它
            if (str_contains($role, ',')) {
                $allowedRoles = array_merge($allowedRoles, array_map('trim', explode(',', $role)));
            } else {
                $allowedRoles[] = trim($role);
            }
        }

        // 去重
        $allowedRoles = array_unique($allowedRoles);

        // 检查用户角色是否在允许的角色列表中
        $userRoleValue = $user->role->value;
        $isAllowed = in_array($userRoleValue, $allowedRoles, true);

        if (!$isAllowed) {
            return response()->json([
                'message' => '权限不足',
            ], 403);
        }

        return $next($request);
    }
}
