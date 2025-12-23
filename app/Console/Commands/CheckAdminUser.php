<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CheckAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:check {--create : 如果不存在则创建} {--reset : 重置密码为 admin123456}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查或创建管理员账号';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = 'admin@example.com';
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->warn("管理员账号不存在: {$email}");

            if ($this->option('create')) {
                $user = User::create([
                    'name' => '超级管理员',
                    'email' => $email,
                    'password' => Hash::make('admin123456'),
                    'role' => UserRole::ADMIN,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);

                $this->info("✅ 管理员账号已创建！");
                $this->info("   邮箱: {$email}");
                $this->info("   密码: admin123456");
            } else {
                $this->info("💡 提示: 运行 'php artisan admin:check --create' 来创建管理员账号");
            }
        } else {
            $this->info("✅ 管理员账号已存在");
            $this->info("   邮箱: {$user->email}");
            $this->info("   姓名: {$user->name}");
            $this->info("   角色: {$user->role->label()}");
            $this->info("   状态: " . ($user->is_active ? '✅ 启用' : '❌ 禁用'));

            if ($this->option('reset')) {
                $user->password = Hash::make('admin123456');
                $user->is_active = true;
                $user->save();

                $this->info("✅ 密码已重置为: admin123456");
                $this->info("✅ 账号状态已设置为: 启用");
            } else {
                // 测试密码
                if (Hash::check('admin123456', $user->password)) {
                    $this->info("   密码: ✅ 正确 (admin123456)");
                } else {
                    $this->warn("   密码: ❌ 不是默认密码 (admin123456)");
                    $this->info("💡 提示: 运行 'php artisan admin:check --reset' 来重置密码");
                }
            }
        }

        return 0;
    }
}

