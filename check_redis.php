<?php
/**
 * Redis 扩展诊断脚本
 * 在服务器上运行：php check_redis.php
 */

echo "=== PHP Redis 扩展诊断 ===\n\n";

// 1. 检查 PHP 版本
echo "1. PHP 版本: " . PHP_VERSION . "\n";

// 2. 检查 php.ini 文件位置
echo "2. php.ini 文件位置:\n";
echo "   CLI: " . php_ini_loaded_file() . "\n";
$additionalIni = php_ini_scanned_files();
if ($additionalIni) {
    echo "   额外配置文件: " . $additionalIni . "\n";
}

// 3. 检查 Redis 扩展是否加载
echo "\n3. Redis 扩展检查:\n";
if (extension_loaded('redis')) {
    echo "   ✅ Redis 扩展已加载\n";
    $redisVersion = phpversion('redis');
    echo "   版本: " . ($redisVersion ?: '未知') . "\n";
} else {
    echo "   ❌ Redis 扩展未加载\n";
}

// 4. 检查 Redis 类是否存在
echo "\n4. Redis 类检查:\n";
if (class_exists('Redis')) {
    echo "   ✅ Redis 类存在\n";
} else {
    echo "   ❌ Redis 类不存在\n";
}

// 5. 检查已加载的扩展列表
echo "\n5. 已加载的扩展（包含 'redis' 的）:\n";
$extensions = get_loaded_extensions();
$redisExtensions = array_filter($extensions, function($ext) {
    return stripos($ext, 'redis') !== false;
});
if (empty($redisExtensions)) {
    echo "   ❌ 没有找到 Redis 相关扩展\n";
} else {
    foreach ($redisExtensions as $ext) {
        echo "   ✅ $ext\n";
    }
}

// 6. 检查 Redis 扩展文件位置
echo "\n6. Redis 扩展文件位置:\n";
$extensionDir = ini_get('extension_dir');
echo "   扩展目录: $extensionDir\n";

$redisSo = $extensionDir . '/redis.so';
if (file_exists($redisSo)) {
    echo "   ✅ redis.so 文件存在: $redisSo\n";
} else {
    echo "   ❌ redis.so 文件不存在: $redisSo\n";
    // 尝试查找其他位置
    $possiblePaths = [
        '/usr/lib/php/*/redis.so',
        '/usr/lib64/php/*/redis.so',
        '/opt/remi/php*/root/usr/lib64/php/modules/redis.so',
    ];
    echo "   尝试查找其他位置...\n";
    foreach ($possiblePaths as $pattern) {
        $files = glob($pattern);
        if (!empty($files)) {
            echo "   ✅ 找到: " . $files[0] . "\n";
        }
    }
}

// 7. 检查 php.ini 中的配置
echo "\n7. php.ini 配置检查:\n";
$iniFile = php_ini_loaded_file();
if ($iniFile && file_exists($iniFile)) {
    $iniContent = file_get_contents($iniFile);
    if (preg_match('/extension\s*=\s*redis/i', $iniContent)) {
        echo "   ✅ php.ini 中包含 Redis 扩展配置\n";
        preg_match_all('/extension\s*=\s*redis[^\n]*/i', $iniContent, $matches);
        foreach ($matches[0] as $match) {
            echo "      " . trim($match) . "\n";
        }
    } else {
        echo "   ❌ php.ini 中未找到 Redis 扩展配置\n";
    }
}

// 8. 检查 SAPI（Server API）
echo "\n8. 当前运行环境:\n";
echo "   SAPI: " . php_sapi_name() . "\n";
if (php_sapi_name() === 'cli') {
    echo "   ⚠️  当前是 CLI 模式，Web 服务器可能使用不同的 php.ini\n";
    echo "   请检查 PHP-FPM 或 Apache 使用的 php.ini 文件\n";
}

// 9. 测试 Redis 连接（如果扩展已加载）
echo "\n9. Redis 连接测试:\n";
if (class_exists('Redis')) {
    try {
        $redis = new Redis();
        $connected = @$redis->connect('127.0.0.1', 6379);
        if ($connected) {
            echo "   ✅ Redis 连接成功\n";
            $ping = $redis->ping();
            echo "   Ping 结果: $ping\n";
        } else {
            echo "   ⚠️  Redis 连接失败（可能是 Redis 服务器未启动）\n";
        }
    } catch (Exception $e) {
        echo "   ❌ Redis 连接异常: " . $e->getMessage() . "\n";
    }
} else {
    echo "   ❌ 无法测试连接（Redis 类不存在）\n";
}

echo "\n=== 诊断完成 ===\n";
echo "\n建议：\n";
echo "1. 如果扩展未加载，检查 php.ini 中是否有 'extension=redis' 配置\n";
echo "2. 如果使用 PHP-FPM，确保重启 PHP-FPM 服务\n";
echo "3. 如果使用 Apache，确保重启 Apache 服务\n";
echo "4. 检查 PHP-FPM 使用的 php.ini 文件（通常在 /etc/php/8.x/fpm/php.ini）\n";

