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
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => '未认证',
            ], 401);
        }

        $requiredRole = UserRole::from($role);

        if ($user->role !== $requiredRole && !$user->isAdmin()) {
            return response()->json([
                'message' => '权限不足',
            ], 403);
        }

        return $next($request);
    }
}
