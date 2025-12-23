# 管理员账号信息

## 默认管理员账号

运行数据库种子文件后，系统会自动创建默认管理员账号：

**邮箱：** `admin@example.com`  
**密码：** `admin123456`  
**角色：** 超级管理员 (ADMIN)

## 创建管理员账号

### 方法1：运行 Seeder（推荐）

```bash
php artisan db:seed --class=AdminUserSeeder
```

或者运行所有 Seeder：

```bash
php artisan db:seed
```

### 方法2：使用 Tinker 创建

```bash
php artisan tinker
```

然后在 Tinker 中执行：

```php
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Hash;

User::create([
    'name' => '超级管理员',
    'email' => 'admin@example.com',
    'password' => Hash::make('admin123456'),
    'role' => UserRole::ADMIN,
    'is_active' => true,
    'email_verified_at' => now(),
]);
```

### 方法3：使用 API 创建（需要先有管理员账号）

通过 API 接口创建：

```bash
POST /api/users
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "新管理员",
  "email": "newadmin@example.com",
  "password": "password123",
  "password_confirmation": "password123",
  "role_name": "admin"
}
```

## 修改默认密码

**⚠️ 重要：** 首次登录后，请立即修改默认密码！

可以通过以下方式修改：

1. **前端界面**：登录后进入"账号设置"页面修改密码
2. **API 接口**：`PUT /api/users/{user}/password`

## 安全建议

1. ✅ 首次登录后立即修改默认密码
2. ✅ 使用强密码（至少8位，包含大小写字母、数字和特殊字符）
3. ✅ 定期更换密码
4. ✅ 不要在多个系统使用相同密码
5. ✅ 启用双因素认证（如果系统支持）

## 角色说明

- **ADMIN（超级管理员）**：拥有所有权限，可以管理用户、景区、酒店、产品等所有数据
- **OPERATOR（运营）**：只能管理自己绑定的景区相关数据

## 忘记密码？

如果忘记了管理员密码，可以通过以下方式重置：

1. **使用 Tinker**：
```bash
php artisan tinker
```

```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$user = User::where('email', 'admin@example.com')->first();
$user->password = Hash::make('newpassword123');
$user->save();
```

2. **创建新的管理员账号**（如果还有其他管理员账号）

