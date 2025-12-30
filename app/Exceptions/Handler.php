<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // 优先处理认证异常（必须在其他异常之前）
        $this->renderable(function (AuthenticationException $e, Request $request) {
            // 如果是 API 请求，返回 JSON 响应
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => '未授权，请先登录',
                ], 401);
            }
            // Web 请求让基类处理（会重定向）
            return null;
        });
        
        // 处理路由未找到异常（可能是认证重定向导致的）
        $this->renderable(function (RouteNotFoundException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                // 如果是认证相关的路由未找到，返回认证错误
                if (str_contains($e->getMessage(), 'login')) {
                    return response()->json([
                        'success' => false,
                        'message' => '未授权，请先登录',
                    ], 401);
                }
                return response()->json([
                    'success' => false,
                    'message' => '路由不存在',
                ], 404);
            }
        });
        
        // 处理其他异常
        $this->renderable(function (Throwable $e, Request $request) {
            // 如果是API请求或webhook请求，返回统一的JSON格式
            if ($request->expectsJson() || 
                $request->is('api/*') || 
                $request->is('webhooks/*') ||
                str_contains($request->path(), 'webhooks')) {
                return $this->handleApiException($request, $e);
            }
        });
    }

    /**
     * 处理未认证异常
     * 重写此方法以确保 API 请求返回 JSON 而不是重定向
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // 如果是 API 请求，返回 JSON 响应
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => '未授权，请先登录',
            ], 401);
        }

        // Web 请求重定向到登录页（如果路由存在）
        // 注意：这里不调用 route('login')，因为可能不存在
        return redirect()->guest('/login');
    }

    /**
     * 处理API异常
     */
    protected function handleApiException(Request $request, Throwable $e): JsonResponse
    {
        // 验证异常
        if ($e instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => '数据验证失败',
                'errors' => $e->errors(),
            ], 422);
        }

        // 认证异常
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'success' => false,
                'message' => '未授权，请先登录',
            ], 401);
        }

        // 模型未找到
        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => '资源不存在',
            ], 404);
        }

        // 路由未找到
        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'success' => false,
                'message' => '接口不存在',
            ], 404);
        }

        // 方法不允许
        if ($e instanceof MethodNotAllowedHttpException) {
            return response()->json([
                'success' => false,
                'message' => '请求方法不允许',
            ], 405);
        }

        // 其他异常
        $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        $message = $e->getMessage() ?: '服务器内部错误';

        // 生产环境不暴露详细错误信息
        if (!config('app.debug')) {
            $message = '服务器内部错误';
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'trace' => config('app.debug') ? $e->getTrace() : null,
        ], $statusCode);
    }
}

