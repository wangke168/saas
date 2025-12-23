# 登录问题修复说明

## 问题描述

使用邮箱 `admin@example.com` 和密码 `admin123456` 登录时，出现错误：
```
The POST method is not supported for route api/api/auth/login. Supported methods: GET, HEAD.
```

## 问题原因

路径重复：`api/api/auth/login`

- axios 的 `baseURL` 设置为 `/api`
- `auth.js` 中使用了 `/api/auth/login`
- 实际请求路径变成了 `/api` + `/api/auth/login` = `/api/api/auth/login`

## 修复内容

已修复 `resources/js/stores/auth.js` 中的 API 路径：

**修复前：**
```javascript
axios.post('/api/auth/login', { email, password })  // ❌ 错误
axios.post('/api/auth/logout')                       // ❌ 错误
axios.get('/api/auth/me')                           // ❌ 错误
```

**修复后：**
```javascript
axios.post('/auth/login', { email, password })      // ✅ 正确
axios.post('/auth/logout')                          // ✅ 正确
axios.get('/auth/me')                               // ✅ 正确
```

## 验证

修复后，登录请求路径为：
- axios baseURL: `/api`
- 请求路径: `/auth/login`
- 最终路径: `/api/auth/login` ✅

## 测试步骤

1. 确保已运行 `npm run dev`（前端开发服务器）
2. 确保已运行 `php artisan serve`（后端服务器）
3. 访问登录页面
4. 使用以下凭据登录：
   - **邮箱：** `admin@example.com`
   - **密码：** `admin123456`

## 其他 API 调用

其他文件中的 API 调用都是正确的，因为它们使用的是相对路径：
- `/users` → `/api/users` ✅
- `/products` → `/api/products` ✅
- `/orders` → `/api/orders` ✅

## 注意事项

如果修改了 axios 的 `baseURL`，需要确保：
1. 所有 API 调用使用相对路径（不以 `/api` 开头）
2. 或者修改 `baseURL` 为 `/`，然后在所有调用中使用完整路径 `/api/...`

当前配置（推荐）：
- `baseURL: '/api'`
- API 调用使用相对路径：`/auth/login`、`/users` 等

