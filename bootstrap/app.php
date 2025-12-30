<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);
        // 注意：EnsureFrontendRequestsAreStateful 可能导致重定向
        // 如果不需要状态认证，可以移除或配置
        $middleware->api(prepend: [
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
        
        // 配置认证中间件：API 请求返回 JSON，不重定向
        $middleware->redirectGuestsTo(function (Request $request) {
            // 如果是 API 请求，返回 null（不重定向，让异常处理器处理）
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }
            // Web 请求重定向到登录页（如果需要）
            return '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 异常处理已在 Handler 类中注册
    })->create();