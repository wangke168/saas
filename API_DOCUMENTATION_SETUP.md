# API 文档设置指南

## 使用 Scribe 生成 API 文档

### 1. 安装 Scribe

```bash
composer require knuckleswtf/scribe --dev
```

### 2. 发布配置文件

```bash
php artisan vendor:publish --tag=scribe-config
```

### 3. 配置 Scribe

编辑 `config/scribe.php`，设置基本信息：

```php
return [
    'title' => 'OTA酒景套餐分销系统 API 文档',
    'description' => 'OTA酒景套餐分销系统接口文档',
    'base_url' => env('APP_URL', 'http://localhost'),
    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/*'],
            ],
        ],
    ],
];
```

### 4. 生成文档

```bash
php artisan scribe:generate
```

### 5. 访问文档

文档将生成在 `public/docs/index.html`，访问地址：
- 本地：`http://localhost/docs`
- 生产环境：`https://your-domain.com/docs`

## 在控制器中添加文档注释

示例：

```php
/**
 * @group 订单管理
 * 
 * 创建订单
 */
public function store(Request $request)
{
    /**
     * @bodyParam ota_platform_id integer required OTA平台ID
     * @bodyParam product_id integer required 产品ID
     * @bodyParam check_in_date string required 入住日期 (格式: Y-m-d)
     * @bodyParam check_out_date string required 离店日期 (格式: Y-m-d)
     * @bodyParam room_count integer required 房间数
     * @bodyParam contact_name string required 联系人姓名
     * @bodyParam contact_phone string required 联系人电话
     * 
     * @response 201 {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "order_no": "ORD20231221001",
     *     "status": "paid_pending"
     *   }
     * }
     */
    // ... 控制器代码
}
```

## 使用 l5-swagger (替代方案)

如果不想使用 Scribe，可以使用 l5-swagger：

### 1. 安装

```bash
composer require darkaonline/l5-swagger
php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider"
```

### 2. 生成文档

```bash
php artisan l5-swagger:generate
```

### 3. 访问文档

访问 `http://localhost/api/documentation`

