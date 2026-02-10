<?php

/**
 * 快速创建测试用户脚本
 * 使用方法: php create_test_user.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Hash;

$email = 'wangke168@gmail.com';
$password = '*a471000*';

// 检查用户是否存在
$user = User::where('email', $email)->first();

if ($user) {
    echo "用户已存在: {$email}\n";
    echo "用户ID: {$user->id}\n";
    echo "用户名: {$user->name}\n";
    echo "是否启用: " . ($user->is_active ? '是' : '否') . "\n";
    echo "\n";
    
    // 测试密码
    if (Hash::check($password, $user->password)) {
        echo "✓ 密码正确\n";
    } else {
        echo "✗ 密码不正确\n";
        echo "\n";
        echo "是否重置密码为: {$password}? (y/n): ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        if (trim($line) === 'y') {
            $user->password = Hash::make($password);
            $user->is_active = true;
            $user->save();
            echo "✓ 密码已重置\n";
        }
        fclose($handle);
    }
} else {
    echo "用户不存在，创建新用户...\n";
    
    $user = User::create([
        'name' => '测试用户',
        'email' => $email,
        'password' => Hash::make($password),
        'role' => UserRole::ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    
    echo "✓ 用户创建成功！\n";
    echo "邮箱: {$email}\n";
    echo "密码: {$password}\n";
    echo "用户ID: {$user->id}\n";
}

echo "\n";
echo "现在可以尝试登录了。\n";




