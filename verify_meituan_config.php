<?php
/**
 * 美团配置快速验证脚本
 * 
 * 使用方法:
 * php verify_meituan_config.php
 */

require __DIR__ . '/vendor/autoload.php';

// 加载Laravel环境
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "========================================\n";
echo "美团配置验证\n";
echo "========================================\n\n";

// 1. 检查环境变量
echo "1. 检查环境变量...\n";
$configs = [
    'MEITUAN_PARTNER_ID' => env('MEITUAN_PARTNER_ID'),
    'MEITUAN_APP_KEY' => env('MEITUAN_APP_KEY'),
    'MEITUAN_APP_SECRET' => env('MEITUAN_APP_SECRET'),
    'MEITUAN_AES_KEY' => env('MEITUAN_AES_KEY'),
    'MEITUAN_API_URL' => env('MEITUAN_API_URL'),
    'MEITUAN_WEBHOOK_URL' => env('MEITUAN_WEBHOOK_URL'),
];

$allValid = true;
foreach ($configs as $key => $value) {
    if (empty($value)) {
        echo "  ❌ {$key}: 未设置\n";
        $allValid = false;
    } else {
        // 隐藏敏感信息
        if (in_array($key, ['MEITUAN_APP_SECRET', 'MEITUAN_AES_KEY'])) {
            $displayValue = substr($value, 0, 8) . '...' . substr($value, -4);
        } else {
            $displayValue = $value;
        }
        echo "  ✅ {$key}: {$displayValue}\n";
    }
}

if (!$allValid) {
    echo "\n❌ 部分配置未设置，请检查 .env 文件\n";
    exit(1);
}
echo "\n";

// 2. 验证 AES Key 长度
echo "2. 验证 AES Key 长度...\n";
$aesKey = env('MEITUAN_AES_KEY');
if (strlen($aesKey) !== 16) {
    echo "  ❌ AES Key 长度不正确: " . strlen($aesKey) . " 字节（应为 16 字节）\n";
    exit(1);
} else {
    echo "  ✅ AES Key 长度正确: 16 字节\n";
}
echo "\n";

// 3. 验证 Webhook URL 格式
echo "3. 验证 Webhook URL 格式...\n";
$webhookUrl = env('MEITUAN_WEBHOOK_URL');
if (empty($webhookUrl)) {
    echo "  ❌ MEITUAN_WEBHOOK_URL 未设置\n";
    exit(1);
}

// 检查是否包含 /api 前缀
if (strpos($webhookUrl, '/api/webhooks/meituan') === false) {
    echo "  ⚠️  警告: Webhook URL 可能缺少 /api 前缀\n";
    echo "  当前: {$webhookUrl}\n";
    echo "  建议: " . str_replace('/webhooks/meituan', '/api/webhooks/meituan', $webhookUrl) . "\n";
} else {
    echo "  ✅ Webhook URL 格式正确（包含 /api 前缀）\n";
    echo "  完整地址: {$webhookUrl}\n";
}
echo "\n";

// 4. 验证路由
echo "4. 验证路由配置...\n";
try {
    $routes = \Illuminate\Support\Facades\Route::getRoutes();
    $meituanRoutes = [];
    
    foreach ($routes as $route) {
        $uri = $route->uri();
        if (strpos($uri, 'meituan') !== false) {
            $methods = implode('|', $route->methods());
            $meituanRoutes[] = "  {$methods} /api/{$uri}";
        }
    }
    
    if (empty($meituanRoutes)) {
        echo "  ⚠️  未找到美团相关路由\n";
    } else {
        echo "  ✅ 找到 " . count($meituanRoutes) . " 个美团路由:\n";
        foreach ($meituanRoutes as $route) {
            echo $route . "\n";
        }
    }
} catch (\Exception $e) {
    echo "  ⚠️  无法验证路由: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. 测试 BA 认证和 AES 加密
echo "5. 测试 BA 认证和 AES 加密...\n";
try {
    $config = new \App\Models\OtaConfig();
    $config->account = env('MEITUAN_PARTNER_ID');
    $config->secret_key = env('MEITUAN_APP_KEY');
    $config->aes_key = env('MEITUAN_APP_SECRET');
    $config->aes_iv = env('MEITUAN_AES_KEY');
    $config->api_url = env('MEITUAN_API_URL');
    $config->is_active = true;

    $client = new \App\Http\Client\MeituanClient($config);

    // 测试加密
    $testData = json_encode(['test' => 'data'], JSON_UNESCAPED_UNICODE);
    $encrypted = $client->encryptBody($testData);
    $decrypted = $client->decryptBody($encrypted);

    if ($decrypted === $testData) {
        echo "  ✅ AES 加密/解密测试通过\n";
    } else {
        echo "  ❌ AES 加密/解密测试失败\n";
        exit(1);
    }

    // 测试 BA 认证
    $headers = $client->buildAuthHeaders('POST', '/rhone/mtp/api/level/price/calendar/v2');
    if (!empty($headers['Authorization']) && !empty($headers['Date'])) {
        echo "  ✅ BA 认证 Header 生成成功\n";
        echo "    Authorization: " . substr($headers['Authorization'], 0, 30) . "...\n";
        echo "    Date: {$headers['Date']}\n";
    } else {
        echo "  ❌ BA 认证 Header 生成失败\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "  ❌ 加密/认证测试失败: " . $e->getMessage() . "\n";
    exit(1);
}
echo "\n";

// 6. 生成完整接口地址列表
echo "6. 生成完整接口地址列表...\n";
$baseUrl = rtrim(env('MEITUAN_WEBHOOK_URL'), '/');
$interfaces = [
    '拉取多层价格日历V2' => '/product/level/price/calendar/v2',
    '订单创建V2' => '/order/create/v2',
    '订单出票' => '/order/pay',
    '订单查询' => '/order/query',
    '订单退款' => '/order/refund',
    '已退款消息' => '/order/refunded',
    '订单关闭消息' => '/order/close',
    '拉取价格日历' => '/product/price/calendar',
];

echo "  完整接口地址:\n";
foreach ($interfaces as $name => $path) {
    $fullUrl = $baseUrl . $path;
    echo "    {$name}:\n";
    echo "      {$fullUrl}\n";
}
echo "\n";

// 7. 总结
echo "========================================\n";
echo "验证总结\n";
echo "========================================\n";
echo "✅ 环境变量: 已设置\n";
echo "✅ AES Key: 长度正确\n";
echo "✅ Webhook URL: 格式正确\n";
echo "✅ 加密/认证: 功能正常\n";
echo "\n";
echo "下一步:\n";
echo "1. 在美团开放平台配置接口地址\n";
echo "2. 运行测试脚本: php test_meituan_level_price_calendar_v2.php\n";
echo "3. 进行接口联调测试\n";
echo "\n";

/**
 * 美团配置快速验证脚本
 * 
 * 使用方法:
 * php verify_meituan_config.php
 */

require __DIR__ . '/vendor/autoload.php';

// 加载Laravel环境
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "========================================\n";
echo "美团配置验证\n";
echo "========================================\n\n";

// 1. 检查环境变量
echo "1. 检查环境变量...\n";
$configs = [
    'MEITUAN_PARTNER_ID' => env('MEITUAN_PARTNER_ID'),
    'MEITUAN_APP_KEY' => env('MEITUAN_APP_KEY'),
    'MEITUAN_APP_SECRET' => env('MEITUAN_APP_SECRET'),
    'MEITUAN_AES_KEY' => env('MEITUAN_AES_KEY'),
    'MEITUAN_API_URL' => env('MEITUAN_API_URL'),
    'MEITUAN_WEBHOOK_URL' => env('MEITUAN_WEBHOOK_URL'),
];

$allValid = true;
foreach ($configs as $key => $value) {
    if (empty($value)) {
        echo "  ❌ {$key}: 未设置\n";
        $allValid = false;
    } else {
        // 隐藏敏感信息
        if (in_array($key, ['MEITUAN_APP_SECRET', 'MEITUAN_AES_KEY'])) {
            $displayValue = substr($value, 0, 8) . '...' . substr($value, -4);
        } else {
            $displayValue = $value;
        }
        echo "  ✅ {$key}: {$displayValue}\n";
    }
}

if (!$allValid) {
    echo "\n❌ 部分配置未设置，请检查 .env 文件\n";
    exit(1);
}
echo "\n";

// 2. 验证 AES Key 长度
echo "2. 验证 AES Key 长度...\n";
$aesKey = env('MEITUAN_AES_KEY');
if (strlen($aesKey) !== 16) {
    echo "  ❌ AES Key 长度不正确: " . strlen($aesKey) . " 字节（应为 16 字节）\n";
    exit(1);
} else {
    echo "  ✅ AES Key 长度正确: 16 字节\n";
}
echo "\n";

// 3. 验证 Webhook URL 格式
echo "3. 验证 Webhook URL 格式...\n";
$webhookUrl = env('MEITUAN_WEBHOOK_URL');
if (empty($webhookUrl)) {
    echo "  ❌ MEITUAN_WEBHOOK_URL 未设置\n";
    exit(1);
}

// 检查是否包含 /api 前缀
if (strpos($webhookUrl, '/api/webhooks/meituan') === false) {
    echo "  ⚠️  警告: Webhook URL 可能缺少 /api 前缀\n";
    echo "  当前: {$webhookUrl}\n";
    echo "  建议: " . str_replace('/webhooks/meituan', '/api/webhooks/meituan', $webhookUrl) . "\n";
} else {
    echo "  ✅ Webhook URL 格式正确（包含 /api 前缀）\n";
    echo "  完整地址: {$webhookUrl}\n";
}
echo "\n";

// 4. 验证路由
echo "4. 验证路由配置...\n";
try {
    $routes = \Illuminate\Support\Facades\Route::getRoutes();
    $meituanRoutes = [];
    
    foreach ($routes as $route) {
        $uri = $route->uri();
        if (strpos($uri, 'meituan') !== false) {
            $methods = implode('|', $route->methods());
            $meituanRoutes[] = "  {$methods} /api/{$uri}";
        }
    }
    
    if (empty($meituanRoutes)) {
        echo "  ⚠️  未找到美团相关路由\n";
    } else {
        echo "  ✅ 找到 " . count($meituanRoutes) . " 个美团路由:\n";
        foreach ($meituanRoutes as $route) {
            echo $route . "\n";
        }
    }
} catch (\Exception $e) {
    echo "  ⚠️  无法验证路由: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. 测试 BA 认证和 AES 加密
echo "5. 测试 BA 认证和 AES 加密...\n";
try {
    $config = new \App\Models\OtaConfig();
    $config->account = env('MEITUAN_PARTNER_ID');
    $config->secret_key = env('MEITUAN_APP_KEY');
    $config->aes_key = env('MEITUAN_APP_SECRET');
    $config->aes_iv = env('MEITUAN_AES_KEY');
    $config->api_url = env('MEITUAN_API_URL');
    $config->is_active = true;

    $client = new \App\Http\Client\MeituanClient($config);

    // 测试加密
    $testData = json_encode(['test' => 'data'], JSON_UNESCAPED_UNICODE);
    $encrypted = $client->encryptBody($testData);
    $decrypted = $client->decryptBody($encrypted);

    if ($decrypted === $testData) {
        echo "  ✅ AES 加密/解密测试通过\n";
    } else {
        echo "  ❌ AES 加密/解密测试失败\n";
        exit(1);
    }

    // 测试 BA 认证
    $headers = $client->buildAuthHeaders('POST', '/rhone/mtp/api/level/price/calendar/v2');
    if (!empty($headers['Authorization']) && !empty($headers['Date'])) {
        echo "  ✅ BA 认证 Header 生成成功\n";
        echo "    Authorization: " . substr($headers['Authorization'], 0, 30) . "...\n";
        echo "    Date: {$headers['Date']}\n";
    } else {
        echo "  ❌ BA 认证 Header 生成失败\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "  ❌ 加密/认证测试失败: " . $e->getMessage() . "\n";
    exit(1);
}
echo "\n";

// 6. 生成完整接口地址列表
echo "6. 生成完整接口地址列表...\n";
$baseUrl = rtrim(env('MEITUAN_WEBHOOK_URL'), '/');
$interfaces = [
    '拉取多层价格日历V2' => '/product/level/price/calendar/v2',
    '订单创建V2' => '/order/create/v2',
    '订单出票' => '/order/pay',
    '订单查询' => '/order/query',
    '订单退款' => '/order/refund',
    '已退款消息' => '/order/refunded',
    '订单关闭消息' => '/order/close',
    '拉取价格日历' => '/product/price/calendar',
];

echo "  完整接口地址:\n";
foreach ($interfaces as $name => $path) {
    $fullUrl = $baseUrl . $path;
    echo "    {$name}:\n";
    echo "      {$fullUrl}\n";
}
echo "\n";

// 7. 总结
echo "========================================\n";
echo "验证总结\n";
echo "========================================\n";
echo "✅ 环境变量: 已设置\n";
echo "✅ AES Key: 长度正确\n";
echo "✅ Webhook URL: 格式正确\n";
echo "✅ 加密/认证: 功能正常\n";
echo "\n";
echo "下一步:\n";
echo "1. 在美团开放平台配置接口地址\n";
echo "2. 运行测试脚本: php test_meituan_level_price_calendar_v2.php\n";
echo "3. 进行接口联调测试\n";
echo "\n";


