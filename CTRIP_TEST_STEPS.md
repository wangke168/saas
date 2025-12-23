# 携程沙箱测试步骤

## 一、准备工作

### 1. 配置携程平台信息

在数据库中插入携程平台和配置：

```sql
-- 1. 插入携程平台（如果还没有）
INSERT INTO ota_platforms (name, code, description, is_active, created_at, updated_at)
VALUES ('携程', 'ctrip', '携程旅行网', true, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    name = '携程', 
    description = '携程旅行网',
    updated_at = NOW();

-- 2. 获取平台ID（确保获取到有效的ID）
SET @platform_id = (SELECT id FROM ota_platforms WHERE code = 'ctrip' LIMIT 1);

-- 验证平台ID是否获取成功
SELECT IFNULL(@platform_id, 'ERROR: 平台ID获取失败') AS platform_id_check;

-- 3. 插入或更新携程配置（使用子查询确保平台ID有效）
INSERT INTO ota_configs (
    ota_platform_id, 
    account, 
    secret_key, 
    aes_key, 
    aes_iv, 
    api_url, 
    callback_url, 
    environment, 
    is_active, 
    created_at, 
    updated_at
)
SELECT 
    (SELECT id FROM ota_platforms WHERE code = 'ctrip' LIMIT 1) AS ota_platform_id,
    'a774ec3ee5b649bb' AS account,
    'a0ed6ce96975da24a1aa2d4b1ecd50d5' AS secret_key,
    '9676244592c0d748' AS aes_key,
    'a1e1d0a8f0888b08' AS aes_iv,
    'https://ttdopen.ctrip.com/api' AS api_url,
    'https://7db4f894.r3.cpolar.cn/api/webhooks/ctrip' AS callback_url,
    'sandbox' AS environment,
    true AS is_active,
    NOW() AS created_at,
    NOW() AS updated_at
ON DUPLICATE KEY UPDATE
    account = VALUES(account),
    secret_key = VALUES(secret_key),
    aes_key = VALUES(aes_key),
    aes_iv = VALUES(aes_iv),
    api_url = VALUES(api_url),
    callback_url = VALUES(callback_url),
    updated_at = NOW();
```

**或者使用更简单的方式（分步执行）：**

```sql
-- 方式2：分步执行（推荐）

-- 步骤1：确保平台存在
INSERT IGNORE INTO ota_platforms (name, code, description, is_active, created_at, updated_at)
VALUES ('携程', 'ctrip', '携程旅行网', true, NOW(), NOW());

-- 步骤2：获取平台ID并插入配置
INSERT INTO ota_configs (
    ota_platform_id, 
    account, 
    secret_key, 
    aes_key, 
    aes_iv, 
    api_url, 
    callback_url, 
    environment, 
    is_active, 
    created_at, 
    updated_at
)
SELECT 
    id,
    'a774ec3ee5b649bb',
    'a0ed6ce96975da24a1aa2d4b1ecd50d5',
    '9676244592c0d748',
    'a1e1d0a8f0888b08',
    'https://ttdopen.ctrip.com/api',
    'https://7db4f894.r3.cpolar.cn/api/webhooks/ctrip',
    'sandbox',
    true,
    NOW(),
    NOW()
FROM ota_platforms
WHERE code = 'ctrip'
ON DUPLICATE KEY UPDATE
    account = VALUES(account),
    secret_key = VALUES(secret_key),
    aes_key = VALUES(aes_key),
    aes_iv = VALUES(aes_iv),
    api_url = VALUES(api_url),
    callback_url = VALUES(callback_url),
    updated_at = NOW();
```

### 2. 创建测试产品

#### 产品1：指定日期测试（产品编码：0001）

1. 登录管理后台
2. 进入"产品管理"
3. 创建产品：
   - 产品名称：横店影视城
   - 产品编码：`0001`（重要！必须与测试数据一致）
   - 所属景区：选择一个景区
   - 价格来源：人工维护
4. 在产品详情页设置价格：
   - 日期：2025-12-27
     - 门市价：200.00元
     - 结算价：180.00元
     - 销售价：200.00元
   - 日期：2025-12-28
     - 门市价：100.00元
     - 结算价：90.00元
     - 销售价：100.00元
5. 设置库存（在酒店管理或库存管理中）：
   - 2025-12-27：库存15
   - 2025-12-28：库存20
6. 推送产品到携程：
   - 在产品详情页，点击"推送到OTA平台"
   - 选择"携程"

#### 产品2：非指定日期测试（产品编码：1002）

类似产品1，但：
- 产品编码：`1002`
- 价格和库存设置为非指定日期模式

#### 产品3：订单测试（产品编码：1003）

类似产品1，但：
- 产品编码：`1003`
- 产品名称：横店2天1晚

## 二、价格库存同步测试

### 测试1：单个日期价格同步（测试ID: 11903998）

**重要：** 如果产品还未推送到携程，需要先创建 OTA 产品关联。

**方式1：使用 --auto-push 选项（推荐）**
```bash
# 假设产品ID为1
php artisan ctrip:test-sync --product=1 --type=price --dates=2025-12-27 --auto-push
```

**方式2：手动创建关联（SQL）**
```sql
-- 使用产品编码（推荐）
SET @product_code = '0001';  -- 替换为实际的产品编码
SET @platform_id = (SELECT id FROM ota_platforms WHERE code = 'ctrip' LIMIT 1);

INSERT INTO ota_products (product_id, ota_platform_id, is_active, pushed_at, created_at, updated_at)
SELECT 
    p.id,
    @platform_id,
    true,
    NOW(),
    NOW(),
    NOW()
FROM products p
WHERE p.code = @product_code
  AND NOT EXISTS (
      SELECT 1 FROM ota_products op 
      WHERE op.product_id = p.id AND op.ota_platform_id = @platform_id
  );
```

**方式3：通过管理后台**
在产品详情页点击"推送到OTA平台"，选择"携程"。

**预期结果：** 返回 `resultCode: 0000`，表示成功

### 测试2：多个日期价格同步（测试ID: 11904005）

```bash
php artisan ctrip:test-sync --product=1 --type=price --dates=2025-12-27,2025-12-28
```

### 测试3：单个日期库存同步（测试ID: 11904012）

```bash
php artisan ctrip:test-sync --product=1 --type=stock --dates=2025-12-27
```

### 测试4：多个日期库存同步（测试ID: 11904019）

```bash
php artisan ctrip:test-sync --product=1 --type=stock --dates=2025-12-27,2025-12-28
```

### 测试5：非指定日期价格同步（测试ID: 11903956）

```bash
# 假设产品ID为2
php artisan ctrip:test-sync --product=2 --type=price --date-type=DATE_NOT_REQUIRED
```

### 测试6：非指定日期库存同步（测试ID: 11903963）

```bash
php artisan ctrip:test-sync --product=2 --type=stock --date-type=DATE_NOT_REQUIRED
```

## 三、订单测试

### 1. 在携程沙箱配置回调地址

1. 登录携程沙箱：https://ttdopen.ctrip.com/user/login.do
2. 配置订单回调地址：`https://7db4f894.r3.cpolar.cn/api/webhooks/ctrip`

### 2. 执行订单测试

在携程沙箱中选择对应的测试用例执行：

#### 预下单测试（测试ID: 11905692）

- 测试用例：正常预下单创建
- 携程会调用我们的 `PreCreateOrder` 接口
- 查看日志确认处理结果

#### 预下单支付测试（测试ID: 11905720）

- 测试用例：正常预下单支付
- 携程会调用我们的 `CreateOrder` 接口
- 系统会自动处理订单并调用资源方接口

#### 订单取消测试（测试ID: 11905608）

- 测试用例：正常取消
- 携程会调用我们的 `CancelOrder` 接口

#### 订单查询测试（测试ID: 11905664）

- 测试用例：正常查询
- 携程会调用我们的 `QueryOrder` 接口（注意：根据文档，serviceName 应为 `QueryOrder`，不是 `OrderQuery`）

## 四、查看测试结果

### 1. 查看日志

```bash
# 实时查看携程相关日志
tail -f storage/logs/laravel.log | grep "携程"

# 或者查看所有日志
tail -f storage/logs/laravel.log
```

### 2. 检查数据库

```sql
-- 查看订单
SELECT * FROM orders WHERE ota_platform_id = (SELECT id FROM ota_platforms WHERE code = 'ctrip') ORDER BY created_at DESC;

-- 查看异常订单
SELECT * FROM exception_orders ORDER BY created_at DESC;
```

### 3. 在携程沙箱查看结果

登录携程沙箱，查看测试用例的执行结果。

## 五、常见问题排查

### 1. 签名验证失败

**检查：**
- `secret_key` 是否正确
- 签名算法是否正确：`MD5(accountId+serviceName+requestTime+body+version+signkey)`

**调试：**
```php
// 在 CtripClient::generateSign 中添加日志
Log::debug('签名计算', [
    'accountId' => $accountId,
    'serviceName' => $serviceName,
    'requestTime' => $requestTime,
    'body_length' => strlen($body),
    'version' => $version,
    'sign' => $sign,
]);
```

### 2. AES加密/解密失败

**检查：**
- `aes_key` 和 `aes_iv` 是否正确（都是16位）
- 编码方式是否正确（使用 encodeBytes/decodeBytes）

**调试：**
```php
// 测试加密解密
$client = new CtripClient($config);
$test = '{"test": "data"}';
$encrypted = $client->encrypt($test);
$decrypted = $client->decrypt($encrypted);
// 应该 $decrypted === $test
```

### 3. 产品不存在错误（1002）

**检查：**
- 产品的 `code` 字段是否与携程中配置的 `supplierOptionId` 一致
- 产品是否已推送到携程（`ota_products` 表）

### 4. 库存不足错误（2003）

**检查：**
- 库存是否已同步到携程
- 库存数量是否足够

## 六、测试检查清单

- [ ] 携程平台和配置已创建
- [ ] 测试产品已创建（编码：0001, 1002, 1003）
- [ ] 产品价格已设置
- [ ] 产品库存已设置
- [ ] 产品已推送到携程
- [ ] 回调地址已配置到携程沙箱
- [ ] 价格同步测试通过
- [ ] 库存同步测试通过
- [ ] 预下单测试通过
- [ ] 订单支付测试通过
- [ ] 订单取消测试通过
- [ ] 订单查询测试通过

