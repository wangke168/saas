<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\OtaConfig;
use App\Models\OtaPlatform;

echo "=== 环境变量检查 ===\n";
echo "CTRIP_ACCOUNT_ID: " . env('CTRIP_ACCOUNT_ID') . "\n";
echo "CTRIP_SECRET_KEY: " . env('CTRIP_SECRET_KEY') . "\n";
echo "CTRIP_ENCRYPT_KEY: " . env('CTRIP_ENCRYPT_KEY') . "\n";
echo "CTRIP_ENCRYPT_IV: " . env('CTRIP_ENCRYPT_IV') . "\n\n";

echo "=== 数据库配置检查 ===\n";

$platforms = OtaPlatform::where('code', 'ctrip')->get();
if ($platforms->isEmpty()) {
    echo "未找到携程平台配置\n";
} else {
    foreach ($platforms as $platform) {
        echo "平台: {$platform->name} ({$platform->code})\n";
        if ($platform->config) {
            echo "  账号: {$platform->config->account}\n";
            echo "  密钥: " . substr($platform->config->secret_key, 0, 10) . "...\n";
            echo "  环境: {$platform->config->environment}\n";
            echo "  启用: " . ($platform->config->is_active ? '是' : '否') . "\n";
        } else {
            echo "  无配置数据\n";
        }
        echo "\n";
    }
}

echo "=== OtaConfig 表所有记录 ===\n";
$configs = OtaConfig::all();
if ($configs->isEmpty()) {
    echo "ota_configs 表为空\n";
} else {
    foreach ($configs as $config) {
        echo "ID: {$config->id}\n";
        echo "  平台ID: {$config->ota_platform_id}\n";
        echo "  账号: {$config->account}\n";
        echo "  密钥: " . substr($config->secret_key, 0, 10) . "...\n";
        echo "  环境: {$config->environment}\n";
        echo "  启用: " . ($config->is_active ? '是' : '否') . "\n";
        echo "\n";
    }
}
