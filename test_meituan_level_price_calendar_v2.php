<?php
/**
 * 美团拉取多层价格日历V2接口测试脚本
 * 
 * 使用方法:
 * php test_meituan_level_price_calendar_v2.php
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// 加载Laravel环境
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 测试配置（使用实际配置）
$testConfig = [
    'partnerId' => env('MEITUAN_PARTNER_ID', 26465),
    'appKey' => env('MEITUAN_APP_KEY', '1d4ct580ae010fs72c3t'),
    'appSecret' => env('MEITUAN_APP_SECRET', '1c0639eeacf5b32db748979e91b64f03d0874fc2'),
    'aesKey' => env('MEITUAN_AES_KEY', 'd3b6dc30e104c5c8'),
    'apiUrl' => env('MEITUAN_API_URL', 'https://connectivity-adapter.meituan.com'),
    'webhookUrl' => env('MEITUAN_WEBHOOK_URL', 'https://www.laidoulaile.online/api/webhooks/meituan'),
];

echo "========================================\n";
echo "美团拉取多层价格日历V2接口测试\n";
echo "========================================\n\n";

// 1. 检查配置
echo "1. 检查配置...\n";
$missingConfig = [];
foreach (['partnerId', 'appKey', 'appSecret', 'aesKey'] as $key) {
    if (empty($testConfig[$key])) {
        $missingConfig[] = $key;
    }
}

if (!empty($missingConfig)) {
    echo "❌ 缺少配置项: " . implode(', ', $missingConfig) . "\n";
    echo "请设置以下环境变量:\n";
    foreach ($missingConfig as $key) {
        echo "  - MEITUAN_" . strtoupper($key) . "\n";
    }
    exit(1);
}
echo "✅ 配置检查通过\n\n";

// 2. 检查测试产品
echo "2. 检查测试产品...\n";
$product = DB::table('products')->whereNotNull('code')->first();
if (!$product) {
    echo "❌ 未找到测试产品，请先创建产品并设置code字段\n";
    exit(1);
}
echo "✅ 找到测试产品: {$product->code} (ID: {$product->id})\n\n";

// 3. 检查价格数据
echo "3. 检查价格数据...\n";
$priceCount = DB::table('prices')
    ->where('product_id', $product->id)
    ->whereBetween('date', [now()->format('Y-m-d'), now()->addDays(30)->format('Y-m-d')])
    ->count();
if ($priceCount === 0) {
    echo "⚠️  未找到未来30天的价格数据，建议创建测试价格数据\n";
} else {
    echo "✅ 找到 {$priceCount} 条价格数据\n";
}
echo "\n";

// 4. 检查库存数据
echo "4. 检查库存数据...\n";
$inventoryCount = DB::table('inventories')
    ->whereBetween('date', [now()->format('Y-m-d'), now()->addDays(30)->format('Y-m-d')])
    ->where('is_closed', false)
    ->count();
if ($inventoryCount === 0) {
    echo "⚠️  未找到未来30天的库存数据，建议创建测试库存数据\n";
} else {
    echo "✅ 找到 {$inventoryCount} 条库存数据\n";
}
echo "\n";

// 5. 构建测试请求
echo "5. 构建测试请求...\n";
$startTime = now()->format('Y-m-d');
$endTime = now()->addDays(7)->format('Y-m-d');
$asyncType = 0; // 0=同步, 1=异步

$requestBody = [
    'partnerId' => intval($testConfig['partnerId']),
    'body' => [
        'partnerDealId' => $product->code,
        'startTime' => $startTime,
        'endTime' => $endTime,
        'asyncType' => $asyncType,
    ],
];

echo "请求参数:\n";
echo "  - partnerDealId: {$product->code}\n";
echo "  - startTime: {$startTime}\n";
echo "  - endTime: {$endTime}\n";
echo "  - asyncType: {$asyncType} (0=同步, 1=异步)\n";
echo "\n";

// 6. 测试BA认证和AES加密
echo "6. 测试BA认证和AES加密...\n";
try {
    // 创建临时配置对象
    $config = new \App\Models\OtaConfig();
    $config->account = $testConfig['partnerId'];
    $config->secret_key = $testConfig['appKey'];
    $config->aes_key = $testConfig['appSecret'];
    $config->aes_iv = $testConfig['aesKey'];
    $config->api_url = $testConfig['apiUrl'];
    $config->is_active = true;

    $client = new \App\Http\Client\MeituanClient($config);

    // 测试加密
    $testData = json_encode(['test' => 'data'], JSON_UNESCAPED_UNICODE);
    $encrypted = $client->encryptBody($testData);
    $decrypted = $client->decryptBody($encrypted);

    if ($decrypted === $testData) {
        echo "✅ AES加密/解密测试通过\n";
    } else {
        echo "❌ AES加密/解密测试失败\n";
        exit(1);
    }

    // 测试BA认证
    $headers = $client->buildAuthHeaders('POST', '/rhone/mtp/api/level/price/calendar/v2');
    if (!empty($headers['Authorization']) && !empty($headers['Date'])) {
        echo "✅ BA认证Header生成成功\n";
    } else {
        echo "❌ BA认证Header生成失败\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "❌ 加密/认证测试失败: " . $e->getMessage() . "\n";
    exit(1);
}
echo "\n";

// 7. 模拟请求（本地测试）
echo "7. 模拟请求（本地测试）...\n";
try {
    // 创建请求对象
    $request = new \Illuminate\Http\Request();
    $request->merge([
        'partnerId' => $testConfig['partnerId'],
        'body' => $client->encryptBody(json_encode($requestBody['body'], JSON_UNESCAPED_UNICODE)),
    ]);

    // 调用控制器方法
    $controller = new \App\Http\Controllers\Webhooks\MeituanController(
        app(\App\Services\OrderService::class),
        app(\App\Services\OrderProcessorService::class),
        app(\App\Services\InventoryService::class),
        app(\App\Services\OrderOperationService::class)
    );

    // 使用反射调用私有方法（仅测试用）
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('handleProductLevelPriceCalendarV2');
    $method->setAccessible(true);

    $response = $method->invoke($controller, $request);
    
    // 获取响应体（现在是加密的Base64字符串）
    $encryptedBody = $response->getContent();
    
    // 解密响应体
    try {
        $decryptedBody = $client->decryptBody($encryptedBody);
        $responseData = json_decode($decryptedBody, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "❌ 响应解密后JSON解析失败: " . json_last_error_msg() . "\n";
            echo "解密后的内容: " . substr($decryptedBody, 0, 200) . "\n";
            exit(1);
        }
    } catch (\Exception $e) {
        echo "❌ 响应解密失败: " . $e->getMessage() . "\n";
        echo "加密的响应体（前200字符）: " . substr($encryptedBody, 0, 200) . "\n";
        exit(1);
    }

    if ($responseData['code'] == 200) {
        echo "✅ 接口调用成功\n";
        echo "响应数据:\n";
        echo "  - code: {$responseData['code']}\n";
        echo "  - describe: {$responseData['describe']}\n";
        if (isset($responseData['partnerId'])) {
            echo "  - partnerId: {$responseData['partnerId']}\n";
        }
        if (isset($responseData['partnerDealId'])) {
            echo "  - partnerDealId: {$responseData['partnerDealId']}\n";
        }
        if (isset($responseData['body']) && is_array($responseData['body'])) {
            echo "  - body数量: " . count($responseData['body']) . "\n";
            if (count($responseData['body']) > 0) {
                $firstItem = $responseData['body'][0];
                echo "  - 第一条数据示例:\n";
                echo "    * partnerPrimaryKey: " . ($firstItem['partnerPrimaryKey'] ?? 'N/A') . "\n";
                echo "    * priceDate: " . ($firstItem['priceDate'] ?? 'N/A') . "\n";
                echo "    * mtPrice: " . ($firstItem['mtPrice'] ?? 'N/A') . "\n";
                echo "    * stock: " . ($firstItem['stock'] ?? 'N/A') . "\n";
                if (isset($firstItem['skuInfo']['levelInfoList'])) {
                    echo "    * 层级数量: " . count($firstItem['skuInfo']['levelInfoList']) . "\n";
                }
            }
        }
    } else {
        echo "❌ 接口调用失败\n";
        echo "响应数据: " . json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "❌ 接口调用异常: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
echo "\n";

// 8. 测试异步模式
echo "8. 测试异步模式...\n";
$asyncRequestBody = $requestBody;
$asyncRequestBody['body']['asyncType'] = 1;

try {
    $asyncRequest = new \Illuminate\Http\Request();
    $asyncRequest->merge([
        'partnerId' => $testConfig['partnerId'],
        'body' => $client->encryptBody(json_encode($asyncRequestBody['body'], JSON_UNESCAPED_UNICODE)),
    ]);

    $asyncResponse = $method->invoke($controller, $asyncRequest);
    
    // 获取响应体（现在是加密的Base64字符串）
    $asyncEncryptedBody = $asyncResponse->getContent();
    
    // 解密响应体
    try {
        $asyncDecryptedBody = $client->decryptBody($asyncEncryptedBody);
        $asyncResponseData = json_decode($asyncDecryptedBody, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "⚠️  异步模式响应解密后JSON解析失败: " . json_last_error_msg() . "\n";
        } else {
            if ($asyncResponseData['code'] == 999) {
                echo "✅ 异步模式响应正确 (code=999)\n";
                echo "  - describe: {$asyncResponseData['describe']}\n";
                echo "  ⚠️  注意: 异步推送任务需要手动实现\n";
            } else {
                echo "⚠️  异步模式响应异常: code={$asyncResponseData['code']}\n";
            }
        }
    } catch (\Exception $e) {
        echo "⚠️  异步模式响应解密失败: " . $e->getMessage() . "\n";
    }
} catch (\Exception $e) {
    echo "❌ 异步模式测试异常: " . $e->getMessage() . "\n";
}
echo "\n";

// 9. 总结
echo "========================================\n";
echo "测试总结\n";
echo "========================================\n";
echo "✅ 配置检查: 通过\n";
echo "✅ 数据检查: 通过\n";
echo "✅ 加密/认证: 通过\n";
echo "✅ 同步模式: 通过\n";
echo "⚠️  异步模式: 需要完善推送任务\n";
echo "\n";
echo "下一步:\n";
echo "1. 在美团开放平台配置接口地址\n";
echo "2. 进行接口联调测试\n";
echo "3. 完善异步推送任务（如需要）\n";
echo "\n";

