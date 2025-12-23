# Cpolar 内网穿透配置指南

## 问题描述

使用 cpolar 内网穿透后，通过 HTTPS 域名访问时出现空白页，浏览器控制台显示 CORS 错误。

## 解决方案

### 1. 配置环境变量

在 `.env` 文件中添加以下配置（根据你的 cpolar 域名修改）：

```bash
# Cpolar 提供的 HTTPS 域名
APP_URL=https://7db4f894.r3.cpolar.cn

# Vite 配置
VITE_APP_URL=https://7db4f894.r3.cpolar.cn
VITE_HMR_HOST=7db4f894.r3.cpolar.cn
VITE_HMR_PROTOCOL=wss
```

### 2. 确保 Vite 配置正确

`vite.config.js` 已经配置了：
- `host: '0.0.0.0'` - 监听所有网络接口
- `cors: true` - 启用 CORS
- HMR 配置支持外部访问

### 3. 启动服务

#### 方式一：分别启动（推荐）

**终端1 - 启动 Laravel：**
```bash
php artisan serve --host=0.0.0.0 --port=8000
```

**终端2 - 启动 Vite：**
```bash
npm run dev
```

#### 方式二：使用 composer dev（如果配置了）

```bash
composer dev
```

### 4. 配置 Cpolar

#### 4.1 配置 Laravel 服务（端口 8000）

```bash
cpolar http 8000
```

#### 4.2 配置 Vite 服务（端口 5174）

```bash
cpolar http 5174
```

**重要：** 需要为 Vite 单独创建一个隧道，因为 Vite 开发服务器需要独立的访问。

### 5. 更新环境变量

根据 cpolar 提供的实际域名更新 `.env`：

```bash
# Laravel 应用的域名（cpolar 为 8000 端口创建的）
APP_URL=https://your-laravel-domain.cpolar.cn

# Vite 开发服务器的域名（cpolar 为 5174 端口创建的）
VITE_APP_URL=https://your-vite-domain.cpolar.cn
VITE_HMR_HOST=your-vite-domain.cpolar.cn
VITE_HMR_PROTOCOL=wss
```

### 6. 重启服务

更新环境变量后，需要重启所有服务：

```bash
# 停止当前服务（Ctrl+C）
# 然后重新启动
php artisan serve --host=0.0.0.0 --port=8000
npm run dev
```

## 注意事项

1. **两个隧道**：需要为 Laravel（8000）和 Vite（5174）分别创建 cpolar 隧道
2. **HTTPS 支持**：cpolar 提供 HTTPS，所以 HMR 需要使用 `wss` 协议
3. **域名变化**：cpolar 免费版的域名可能会变化，每次变化后需要更新 `.env`
4. **CORS**：已配置 CORS，允许跨域访问

## 故障排查

### 如果还是空白页：

1. **检查浏览器控制台**：查看是否有其他错误
2. **检查网络请求**：在 Network 标签查看资源是否加载成功
3. **检查 Vite 服务器**：确认 Vite 是否正常运行
4. **检查环境变量**：确认 `.env` 中的域名是否正确
5. **清除缓存**：
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

### 如果 HMR（热更新）不工作：

1. 确认 `VITE_HMR_PROTOCOL` 设置为 `wss`（HTTPS 使用）
2. 确认 `VITE_HMR_HOST` 设置为正确的 cpolar 域名
3. 检查防火墙是否阻止了 WebSocket 连接

## 生产环境建议

在生产环境中，应该：
1. 使用 `npm run build` 构建前端资源
2. 不需要 Vite 开发服务器
3. 只需要一个 cpolar 隧道指向 Laravel 应用

