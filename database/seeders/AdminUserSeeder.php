<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * 创建默认管理员账号
     */
    public function run(): void
    {
        // 检查是否已存在管理员
        $adminExists = User::where('email', 'admin@example.com')->exists();

        if (!$adminExists) {
            User::create([
                'name' => '超级管理员',
                'email' => 'admin@example.com',
                'password' => Hash::make('admin123456'),
                'role' => UserRole::ADMIN,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $this->command->info('默认管理员账号已创建！');
            $this->command->info('邮箱: admin@example.com');
            $this->command->info('密码: admin123456');
        } else {
            $this->command->info('管理员账号已存在，跳过创建。');
        }
    }
}

