# Vite 构建问题解决方案

## 问题描述

错误：`Vite manifest not found at: /Users/wangke/Code/cursor-test/public/build/manifest.json`

这个错误表示前端资源还没有构建，manifest.json 文件不存在。

## 解决方案

### 方案1：开发环境（推荐）

运行开发服务器，Vite 会自动构建并监听文件变化：

```bash
npm run dev
```

这会：
- 启动 Vite 开发服务器
- 自动构建资源文件
- 监听文件变化并热更新
- 生成 manifest.json 文件

### 方案2：生产构建

如果需要构建生产版本：

```bash
npm run build
```

这会：
- 构建并压缩所有资源
- 生成优化后的文件到 `public/build/` 目录
- 生成 manifest.json 文件

### 方案3：临时禁用 Vite（仅用于测试）

如果只是想快速测试后端API，可以临时修改 `.env` 文件：

```env
APP_ENV=local
```

或者修改 `config/app.php` 中的 Vite 配置（不推荐）。

## 完整开发流程

1. **安装依赖**（如果还没安装）：
```bash
npm install
```

2. **启动开发服务器**：
```bash
# 终端1：启动 Laravel 后端
php artisan serve

# 终端2：启动 Vite 前端开发服务器
npm run dev
```

3. **访问应用**：
- 前端：`http://localhost:8000`
- API：`http://localhost:8000/api`

## 常见问题

### Q: npm install 失败？

A: 尝试使用：
```bash
npm install --legacy-peer-deps
```

### Q: 端口冲突？

A: Vite 默认使用 5173 端口，如果被占用，可以修改 `vite.config.js`：
```js
export default defineConfig({
    server: {
        port: 5174, // 或其他可用端口
    },
    // ... 其他配置
});
```

### Q: 构建后文件太大？

A: 确保运行的是生产构建：
```bash
npm run build
```

## 文件结构

构建后的文件结构：
```
public/
  build/
    assets/
      app-[hash].js
      app-[hash].css
    manifest.json  ← 这个文件必须存在
```

## 验证

构建成功后，检查以下文件是否存在：
- `public/build/manifest.json`
- `public/build/assets/app-*.js`
- `public/build/assets/app-*.css`

如果这些文件存在，错误应该就解决了。

