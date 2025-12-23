# 携程接口对接指南

## 一、配置说明

### 1. 沙箱测试环境参数

在 `.env` 文件中配置（或通过管理后台配置）：

```bash
# 携程沙箱配置
CTRIP_ACCOUNT=a774ec3ee5b649bb
CTRIP_SECRET_KEY=a0ed6ce96975da24a1aa2d4b1ecd50d5
CTRIP_AES_KEY=9676244592c0d748
CTRIP_AES_IV=a1e1d0a8f0888b08
CTRIP_API_URL=https://ttdopen.ctrip.com/api
CTRIP_CALLBACK_URL=https://7db4f894.r3.cpolar.cn/api/webhooks/ctrip
```

### 2. 数据库配置

需要在数据库中配置携程平台和配置信息：

```sql
-- 插入携程平台
INSERT INTO ota_platforms (name, code, description, is_active, created_at, updated_at)
VALUES ('携程', 'ctrip', '携程旅行网', true, NOW(), NOW());

-- 插入携程配置（假设平台ID为1）
INSERT INTO ota_configs (ota_platform_id, account, secret_key, aes_key, aes_iv, api_url, callback_url, environment, is_active, created_at, updated_at)
VALUES (
    1,
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
);
```

## 二、接口实现说明

### 1. 价格同步接口

**接口路径：** `/api/products/{product}/sync-price-to-ctrip`

**请求方式：** POST

**请求参数：**
```json
{
    "dates": ["2025-12-27", "2025-12-28"],  // 可选，指定日期数组
    "date_type": "DATE_REQUIRED"  // DATE_REQUIRED 或 DATE_NOT_REQUIRED
}
```

**使用示例：**
```bash
# 同步指定日期的价格
curl -X POST http://localhost:8000/api/products/1/sync-price-to-ctrip \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "dates": ["2025-12-27", "2025-12-28"],
    "date_type": "DATE_REQUIRED"
  }'

# 同步所有价格（非指定日期）
curl -X POST http://localhost:8000/api/products/1/sync-price-to-ctrip \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "date_type": "DATE_NOT_REQUIRED"
  }'
```

### 2. 库存同步接口

**接口路径：** `/api/products/{product}/sync-stock-to-ctrip`

**请求方式：** POST

**请求参数：** 同价格同步接口

### 3. 命令行测试

```bash
# 测试价格同步（指定日期）
php artisan ctrip:test-sync --product=1 --type=price --dates=2025-12-27,2025-12-28

# 测试库存同步（指定日期）
php artisan ctrip:test-sync --product=1 --type=stock --dates=2025-12-27,2025-12-28

# 测试价格和库存同步（非指定日期）
php artisan ctrip:test-sync --product=1 --type=both --date-type=DATE_NOT_REQUIRED
```

## 三、订单回调接口

### 1. 回调地址配置

在携程沙箱中配置回调地址：
```
https://7db4f894.r3.cpolar.cn/api/webhooks/ctrip
```

### 2. 支持的订单操作

- **PreCreateOrder** - 预下单创建
- **CreateOrder** - 预下单支付（订单创建）
- **CancelOrder** - 订单取消
- **QueryOrder** - 订单查询（注意：根据携程文档，serviceName 应为 `QueryOrder`，不是 `OrderQuery`）

### 3. 回调数据格式

携程发送的数据格式：
```json
{
    "header": {
        "accountId": "a774ec3ee5b649bb",
        "serviceName": "PreCreateOrder",
        "requestTime": "2025-12-22 10:00:00",
        "version": "1.0",
        "sign": "签名"
    },
    "body": "加密后的数据（16进制字符串）"
}
```

## 四、测试数据准备

### 1. 创建测试产品

根据 `ctrip_test.md` 中的测试数据：

**产品1：指定日期测试**
- 产品编码：`0001`
- 产品名称：横店影视城
- 日期1：2025-12-27，库存15，销售价200.00，成本价180.00
- 日期2：2025-12-28，库存20，销售价100.00，成本价90.00

**产品2：非指定日期测试**
- 产品编码：`1002`
- 产品名称：横店影视城
- 日期范围：2025-12-20 至 2025-12-31
- 库存：100
- 销售价：100.00
- 成本价：90.00

**产品3：订单测试**
- 产品编码：`1003`
- 产品名称：横店2天1晚
- 日期1：2025-12-27，库存20，销售价200，成本价180
- 日期2：2025-12-28，库存15，销售价100.00，成本价90.00

### 2. 创建测试步骤

1. **创建产品**：通过管理后台创建产品，设置产品编码
2. **推送产品到携程**：在产品详情页点击"推送到OTA平台"，选择携程
3. **设置价格**：为产品设置价格（根据测试数据）
4. **设置库存**：为产品关联的房型设置库存
5. **同步价格**：调用价格同步接口或使用命令行
6. **同步库存**：调用库存同步接口或使用命令行

## 五、沙箱测试流程

### 1. 价格库存测试

#### 测试1：单个日期价格同步（测试ID: 11903998）
```bash
php artisan ctrip:test-sync --product=1 --type=price --dates=2025-12-27
```

#### 测试2：多个日期价格同步（测试ID: 11904005）
```bash
php artisan ctrip:test-sync --product=1 --type=price --dates=2025-12-27,2025-12-28
```

#### 测试3：单个日期库存同步（测试ID: 11904012）
```bash
php artisan ctrip:test-sync --product=1 --type=stock --dates=2025-12-27
```

#### 测试4：多个日期库存同步（测试ID: 11904019）
```bash
php artisan ctrip:test-sync --product=1 --type=stock --dates=2025-12-27,2025-12-28
```

#### 测试5：非指定日期价格同步（测试ID: 11903956）
```bash
php artisan ctrip:test-sync --product=2 --type=price --date-type=DATE_NOT_REQUIRED
```

#### 测试6：非指定日期库存同步（测试ID: 11903963）
```bash
php artisan ctrip:test-sync --product=2 --type=stock --date-type=DATE_NOT_REQUIRED
```

### 2. 订单测试

订单测试需要在携程沙箱平台操作，系统会自动接收回调。

**测试流程：**
1. 在携程沙箱中选择对应的测试用例
2. 携程会调用我们的回调接口
3. 查看日志确认处理结果
4. 在携程沙箱中查看测试结果

**日志查看：**
```bash
tail -f storage/logs/laravel.log | grep "携程"
```

## 六、常见问题

### 1. 签名验证失败

**原因：** 签名算法不正确或密钥配置错误

**解决：** 
- 检查 `secret_key` 配置是否正确
- 确认签名算法：`MD5(accountId+serviceName+requestTime+body+version+signkey)`

### 2. AES解密失败

**原因：** AES密钥或IV配置错误

**解决：**
- 检查 `aes_key` 和 `aes_iv` 配置
- 确认加密模式：AES-128-CBC
- 确认编码格式：16进制字符串

### 3. 产品不存在错误

**原因：** 产品编码不匹配

**解决：**
- 确认产品的 `code` 字段与携程中配置的 `supplierOptionId` 一致
- 确认产品已推送到携程（`ota_products` 表中有记录）

### 4. 库存不足错误

**原因：** 库存数据未同步或库存不足

**解决：**
- 先同步库存到携程
- 确认库存数量足够

## 七、接口返回码

| 返回码 | 说明 |
|--------|------|
| 0000 | 操作成功 |
| 0001 | 供应商账户为空 |
| 0002 | 签名不正确 |
| 0003 | 报文解析失败 |
| 0004 | 请求方法为空 |
| 0005 | 系统处理异常 |
| 1001 | 携程资源编号不存在/错误 |
| 1002 | 供应商PLU不存在/错误 |
| 1003 | 数据参数不合法 |
| 2001 | 携程资源编号不存在/错误（库存） |
| 2002 | 供应商PLU不存在/错误（库存） |
| 2003 | 数据参数不合法（库存） |

## 八、下一步

1. 在携程沙箱中配置回调地址
2. 创建测试产品并设置价格库存
3. 执行价格库存同步测试
4. 在携程沙箱中执行订单测试
5. 查看日志确认处理结果

