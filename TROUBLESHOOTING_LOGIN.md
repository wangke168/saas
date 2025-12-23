# 登录问题排查指南

## 问题：邮箱或密码错误

### 可能的原因

1. **管理员账号不存在**
2. **密码不正确**
3. **账号被禁用** (`is_active = false`)
4. **密码哈希方式不匹配**

## 解决方案

### 方法1：检查管理员账号（推荐）

运行以下命令检查管理员账号状态：

```bash
php artisan admin:check
```

这会显示：
- 账号是否存在
- 账号状态（启用/禁用）
- 密码是否正确

### 方法2：创建管理员账号

如果账号不存在，运行：

```bash
php artisan admin:check --create
```

或者使用 Seeder：

```bash
php artisan db:seed --class=AdminUserSeeder --force
```

### 方法3：重置密码

如果账号存在但密码不对，运行：

```bash
php artisan admin:check --reset
```

这会将密码重置为：`admin123456`

### 方法4：使用 Tinker 手动检查/创建

```bash
php artisan tinker
```

然后执行：

```php
// 检查账号是否存在
use App\Models\User;
$user = User::where('email', 'admin@example.com')->first();

if ($user) {
    echo "账号存在\n";
    echo "姓名: " . $user->name . "\n";
    echo "状态: " . ($user->is_active ? '启用' : '禁用') . "\n";
    echo "角色: " . $user->role->label() . "\n";
    
    // 测试密码
    use Illuminate\Support\Facades\Hash;
    if (Hash::check('admin123456', $user->password)) {
        echo "密码正确\n";
    } else {
        echo "密码不正确，正在重置...\n";
        $user->password = Hash::make('admin123456');
        $user->is_active = true;
        $user->save();
        echo "密码已重置为: admin123456\n";
    }
} else {
    echo "账号不存在，正在创建...\n";
    use App\Enums\UserRole;
    User::create([
        'name' => '超级管理员',
        'email' => 'admin@example.com',
        'password' => Hash::make('admin123456'),
        'role' => UserRole::ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    echo "账号已创建！\n";
    echo "邮箱: admin@example.com\n";
    echo "密码: admin123456\n";
}
```

## 默认管理员账号信息

- **邮箱：** `admin@example.com`
- **密码：** `admin123456`
- **角色：** 超级管理员 (ADMIN)
- **状态：** 启用

## 验证步骤

1. **检查账号是否存在**：
   ```bash
   php artisan admin:check
   ```

2. **如果不存在，创建账号**：
   ```bash
   php artisan admin:check --create
   ```

3. **如果密码不对，重置密码**：
   ```bash
   php artisan admin:check --reset
   ```

4. **尝试登录**：
   - 邮箱：`admin@example.com`
   - 密码：`admin123456`

## 常见问题

### Q: 运行 `admin:check` 提示账号不存在？

A: 运行 `php artisan admin:check --create` 创建账号。

### Q: 账号存在但登录失败？

A: 运行 `php artisan admin:check --reset` 重置密码。

### Q: 提示"账号已被禁用"？

A: 运行 `php artisan admin:check --reset` 会同时启用账号。

### Q: 仍然无法登录？

A: 检查：
1. 数据库连接是否正常
2. `users` 表是否存在
3. 运行 `php artisan migrate` 确保表结构正确
4. 检查 Laravel 日志：`storage/logs/laravel.log`

## 改进的登录逻辑

登录逻辑已改进，现在会：
1. ✅ 检查用户是否存在
2. ✅ 检查账号是否被禁用
3. ✅ 提供更详细的错误信息

