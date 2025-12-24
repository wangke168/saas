<?php

// 直接读取 .env 文件
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $content = file_get_contents($envFile);
    if (preg_match('/CTRIP_ACCOUNT_ID=(.+)/', $content, $matches)) {
        echo "CTRIP_ACCOUNT_ID from .env: {$matches[1]}\n";
    } else {
        echo "CTRIP_ACCOUNT_ID not found in .env\n";
    }

    if (preg_match('/CTRIP_SECRET_KEY=(.+)/', $content, $matches)) {
        echo "CTRIP_SECRET_KEY from .env: {$matches[1]}\n";
    } else {
        echo "CTRIP_SECRET_KEY not found in .env\n";
    }
} else {
    echo ".env file not found\n";
}

// 检查 Laravel 环境变量
require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';

echo "\nLaravel env() function:\n";
echo "CTRIP_ACCOUNT_ID: " . env('CTRIP_ACCOUNT_ID') . "\n";
echo "CTRIP_SECRET_KEY: " . env('CTRIP_SECRET_KEY') . "\n";
