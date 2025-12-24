# 携程环境参数说明

## 当前代码使用的携程参数

根据代码分析，系统从 `.env` 文件中读取以下携程相关参数：

### 1. 基础认证参数（必需）

| 环境变量名 | 说明 | 读取位置 | 默认值 |
|-----------|------|---------|--------|
| `CTRIP_ACCOUNT_ID` | 携程接口账号 | `CtripService::createConfigFromEnv()` | 无（必需） |
| `CTRIP_SECRET_KEY` | 携程接口密钥（用于签名） | `CtripService::createConfigFromEnv()` | 无（必需） |
| `CTRIP_ENCRYPT_KEY` | AES加密密钥（16位） | `CtripService::createConfigFromEnv()` | 空字符串 |
| `CTRIP_ENCRYPT_IV` | AES加密初始向量（16位） | `CtripService::createConfigFromEnv()` | 空字符串 |

### 2. API 接口地址（可选，有默认值）

| 环境变量名 | 说明 | 读取位置 | 默认值 |
|-----------|------|---------|--------|
| `CTRIP_PRICE_API_URL` | 价格同步接口地址 | `CtripClient::syncPrice()` | `https://ttdopen.ctrip.com/api/product/price.do` |
| `CTRIP_STOCK_API_URL` | 库存同步接口地址 | `CtripClient::syncStock()` | `https://ttdopen.ctrip.com/api/product/stock.do` |
| `CTRIP_ORDER_API_URL` | 订单接口地址（核销通知等） | `CtripClient::notifyOrderConsumed()` | `https://ttdopen.ctrip.com/api/order/notice.do` |

### 3. 回调地址（可选）

| 环境变量名 | 说明 | 读取位置 | 默认值 |
|-----------|------|---------|--------|
| `CTRIP_WEBHOOK_URL` | 携程回调通知地址（商家接口地址） | `CtripService::createConfigFromEnv()` | 空字符串 |

## 参数读取优先级

### 1. 配置来源优先级

代码会按以下顺序查找配置：

1. **数据库配置**（`ota_configs` 表）
   - 如果数据库中存在携程平台的配置，优先使用数据库配置
   - 通过 `OtaPlatform` 和 `OtaConfig` 模型关联

2. **环境变量配置**（`.env` 文件）
   - 如果数据库配置不存在，从 `.env` 文件读取
   - 通过 `CtripService::createConfigFromEnv()` 方法创建临时配置对象

### 2. API URL 优先级

对于 API URL，代码会按以下优先级选择：

1. **环境变量中的特定 URL**（最高优先级）
   - `CTRIP_PRICE_API_URL` → 价格同步接口
   - `CTRIP_STOCK_API_URL` → 库存同步接口
   - `CTRIP_ORDER_API_URL` → 订单接口

2. **配置中的 URL**（`OtaConfig::api_url`）
   - 如果环境变量不存在，使用配置中的 URL

3. **默认 URL**（最低优先级）
   - 如果以上都不存在，使用代码中的默认 URL

## 代码实现位置

### 1. 配置读取

**文件**: `app/Services/OTA/CtripService.php`

```php
protected function createConfigFromEnv(): ?OtaConfig
{
    // 检查必需参数
    if (!env('CTRIP_ACCOUNT_ID') || !env('CTRIP_SECRET_KEY')) {
        return null;
    }

    $config = new OtaConfig();
    $config->account = env('CTRIP_ACCOUNT_ID');
    $config->secret_key = env('CTRIP_SECRET_KEY');
    $config->aes_key = env('CTRIP_ENCRYPT_KEY', '');
    $config->aes_iv = env('CTRIP_ENCRYPT_IV', '');
    
    // API URL 配置
    $priceApiUrl = env('CTRIP_PRICE_API_URL', 'https://ttdopen.ctrip.com/api/product/price.do');
    $stockApiUrl = env('CTRIP_STOCK_API_URL', 'https://ttdopen.ctrip.com/api/product/stock.do');
    $orderApiUrl = env('CTRIP_ORDER_API_URL', 'https://ttdopen.ctrip.com/api/order/notice.do');
    
    $config->api_url = $priceApiUrl;
    $config->callback_url = env('CTRIP_WEBHOOK_URL', '');
    $config->environment = 'production';
    $config->is_active = true;

    return $config;
}
```

### 2. API URL 使用

**文件**: `app/Http/Client/CtripClient.php`

#### 价格同步接口
```php
public function syncPrice(array $bodyData): array
{
    // 优先使用环境变量中的价格API URL
    $url = env('CTRIP_PRICE_API_URL');
    
    // 如果环境变量不存在，使用配置中的URL
    if (!$url) {
        // ... 使用配置或默认URL
    }
    // ...
}
```

#### 库存同步接口
```php
public function syncStock(array $bodyData): array
{
    // 优先使用环境变量中的库存API URL
    $url = env('CTRIP_STOCK_API_URL');
    
    // 如果环境变量不存在，使用配置中的URL
    if (!$url) {
        // ... 使用配置或默认URL
    }
    // ...
}
```

#### 订单接口
```php
public function notifyOrderConsumed(...): array
{
    // 优先使用环境变量中的订单API URL
    $url = env('CTRIP_ORDER_API_URL');
    
    // 如果环境变量不存在，使用配置中的URL
    if (!$url) {
        // ... 使用配置或默认URL
    }
    // ...
}
```

## .env 文件配置示例

```env
# 携程接口认证参数（必需）
CTRIP_ACCOUNT_ID=f4f74fea0b245fb6
CTRIP_SECRET_KEY=12b5c42b74ef4dacbec5a24951327705
CTRIP_ENCRYPT_KEY=523bd2dcf179a217
CTRIP_ENCRYPT_IV=45042d64866cf6d1

# 携程API接口地址（可选，有默认值）
CTRIP_PRICE_API_URL=https://ttdopen.ctrip.com/api/product/price.do
CTRIP_STOCK_API_URL=https://ttdopen.ctrip.com/api/product/stock.do
CTRIP_ORDER_API_URL=https://ttdopen.ctrip.com/api/order/notice.do

# 携程回调地址（可选）
CTRIP_WEBHOOK_URL=https://www.laidoulaile.online/api/webhooks/ctrip
```

## 注意事项

1. **必需参数**：
   - `CTRIP_ACCOUNT_ID` 和 `CTRIP_SECRET_KEY` 是必需的
   - 如果这两个参数不存在，系统无法创建携程配置

2. **加密参数**：
   - `CTRIP_ENCRYPT_KEY` 和 `CTRIP_ENCRYPT_IV` 用于 AES-128-CBC 加密
   - 如果为空，加密/解密会失败

3. **API URL**：
   - 如果 `.env` 中配置了特定的 API URL，会优先使用
   - 如果没有配置，会使用代码中的默认值
   - 正式环境和沙箱环境的 URL 可能不同

4. **环境标识**：
   - 代码中硬编码 `environment = 'production'`（在 `createConfigFromEnv()` 中）
   - 表示使用正式环境配置

## 验证配置

可以通过以下方式验证配置是否正确：

1. **检查日志**：
   - 查看 `storage/logs/laravel.log` 中的携程API请求日志
   - 确认使用的 URL 和账号是否正确

2. **测试接口**：
   - 使用 `php artisan ctrip:test-sync` 命令测试价格/库存同步
   - 查看返回结果确认配置是否正确

3. **检查环境变量**：
   - 使用 `php artisan tinker` 执行 `env('CTRIP_ACCOUNT_ID')` 查看配置值


