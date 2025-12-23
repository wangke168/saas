# Laravel Sanctum 安装指南

## 问题

错误：`Trait "Laravel\Sanctum\HasApiTokens" not found`

这是因为 Laravel Sanctum 包还没有安装。

## 解决方案

### 1. 安装 Sanctum

在终端运行：

```bash
composer require laravel/sanctum
```

### 2. 发布配置文件（可选）

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

### 3. 运行迁移

Sanctum 需要创建 `personal_access_tokens` 表：

```bash
php artisan migrate
```

### 4. 配置 Sanctum（如果使用 SPA）

如果前端应用和 API 在同一域名下，需要在 `config/sanctum.php` 中配置：

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
    '%s%s',
    'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
    env('APP_URL') ? ','.parse_url(env('APP_URL'), PHP_URL_HOST) : ''
))),
```

### 5. 配置中间件

在 `bootstrap/app.php` 中，确保 API 路由使用了 Sanctum 中间件：

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->statefulApi();
})
```

或者手动添加：

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(prepend: [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    ]);
})
```

## 验证安装

安装完成后，检查：

1. `vendor/laravel/sanctum` 目录是否存在
2. `database/migrations` 中是否有 `*_create_personal_access_tokens_table.php` 迁移文件
3. 运行 `php artisan migrate` 确保表已创建

## 使用说明

Sanctum 已经配置在 `User` 模型中：

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    // ...
}
```

在控制器中使用：

```php
// 创建 token
$token = $user->createToken('api-token')->plainTextToken;

// 验证 token（在路由中使用）
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
```

## 注意事项

- Sanctum 用于 API 认证，支持 token 和 SPA 两种模式
- 当前系统使用 token 模式（Bearer Token）
- Token 存储在 `personal_access_tokens` 表中
- 用户登出时会删除当前 token

