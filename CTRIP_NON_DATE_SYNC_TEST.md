# 携程非指定日期价格库存同步测试

## 测试数据

根据 `ctrip_test.md` 的测试数据：
- 产品编码：1002
- 销售价格：100.00 元
- 成本价格：90.00 元
- 库存数量：100

## 测试步骤

### 1. 准备工作

确保产品已配置：
- 产品编码（code）设置为 `1002`
- 产品已推送到携程（ota_products 表中有记录）
- 产品有价格数据（至少一条价格记录，销售价格 10000 分，成本价格 9000 分）
- 产品有库存数据（至少一条库存记录，可用库存 100）

### 2. 测试非指定日期价格同步

**测试命令：**
```bash
php artisan ctrip:test-sync --product=2 --type=price --date-type=DATE_NOT_REQUIRED --auto-push
```

**说明：**
- `--product=2`：产品ID（请根据实际情况修改）
- `--type=price`：只同步价格
- `--date-type=DATE_NOT_REQUIRED`：非指定日期模式
- `--auto-push`：如果产品未推送到携程，自动创建推送关联

**预期结果：**
- 请求体中的 `dateType` 为 `DATE_NOT_REQUIRED`
- `prices` 数组只包含一个价格项，不包含 `date` 字段
- 价格项包含 `salePrice: 100.00` 和 `costPrice: 90.00`
- 携程返回 `resultCode: 0000` 表示成功

### 3. 测试非指定日期库存同步

**测试命令：**
```bash
php artisan ctrip:test-sync --product=2 --type=stock --date-type=DATE_NOT_REQUIRED --auto-push
```

**说明：**
- `--product=2`：产品ID（请根据实际情况修改）
- `--type=stock`：只同步库存
- `--date-type=DATE_NOT_REQUIRED`：非指定日期模式
- `--auto-push`：如果产品未推送到携程，自动创建推送关联

**预期结果：**
- 请求体中的 `dateType` 为 `DATE_NOT_REQUIRED`
- `inventorys` 数组只包含一个库存项，不包含 `date` 字段
- 库存项包含 `quantity: 100`（汇总所有日期的库存）
- 携程返回 `resultCode: 0000` 表示成功

### 4. 同时测试价格和库存同步

**测试命令：**
```bash
php artisan ctrip:test-sync --product=2 --type=both --date-type=DATE_NOT_REQUIRED --auto-push
```

## 接口说明

### 非指定日期价格同步接口

**接口地址：**
- 沙箱环境：`https://ttdopen.ctrip.com/api/product/price.do`
- 生产环境：`https://ttdopen.ctrip.com/api/product/DatePriceModify.do`

**请求格式：**
```json
{
  "header": {
    "accountId": "a774ec3ee5b649bb",
    "serviceName": "DatePriceModify",
    "requestTime": "2025-12-23 10:00:00",
    "version": "1.0",
    "sign": "..."
  },
  "body": "加密后的JSON字符串"
}
```

**请求体（解密后）：**
```json
{
  "sequenceId": "2025-12-23...",
  "supplierOptionId": "1002",
  "dateType": "DATE_NOT_REQUIRED",
  "prices": [
    {
      "salePrice": 100.00,
      "costPrice": 90.00
    }
  ]
}
```

**注意：** 非指定日期模式下，`prices` 数组中的项不包含 `date` 字段。

### 非指定日期库存同步接口

**接口地址：**
- 沙箱环境：`https://ttdopen.ctrip.com/api/product/stock.do`
- 生产环境：`https://ttdopen.ctrip.com/api/product/DateInventoryModify.do`

**请求格式：**
```json
{
  "header": {
    "accountId": "a774ec3ee5b649bb",
    "serviceName": "DateInventoryModify",
    "requestTime": "2025-12-23 10:00:00",
    "version": "1.0",
    "sign": "..."
  },
  "body": "加密后的JSON字符串"
}
```

**请求体（解密后）：**
```json
{
  "sequenceId": "2025-12-23...",
  "supplierOptionId": "1002",
  "dateType": "DATE_NOT_REQUIRED",
  "inventorys": [
    {
      "quantity": 100
    }
  ]
}
```

**注意：** 非指定日期模式下，`inventorys` 数组中的项不包含 `date` 字段。

## 实现说明

### 代码修改

1. **CtripService::syncProductPrice()**
   - 当 `dateType === 'DATE_NOT_REQUIRED'` 时，只取第一个价格项，不包含 `date` 字段

2. **CtripService::syncProductStock()**
   - 当 `dateType === 'DATE_NOT_REQUIRED'` 时，汇总所有日期的库存，只传一个库存项，不包含 `date` 字段

3. **TestCtripSync 命令**
   - 支持 `--date-type=DATE_NOT_REQUIRED` 选项
   - 在非指定日期模式下，自动忽略 `--dates` 参数

## 常见问题

### Q: 为什么非指定日期模式只传一个价格项？

A: 根据携程接口文档，非指定日期模式（`DATE_NOT_REQUIRED`）表示预订时客人无需选择游玩日期，因此只需要一个统一的价格。系统会取第一个价格作为非指定日期的价格。

### Q: 为什么非指定日期模式汇总所有库存？

A: 非指定日期模式表示产品在任何日期都可以使用，因此需要汇总所有日期的库存作为总库存。

### Q: 如何查看请求和响应的详细信息？

A: 查看日志文件 `storage/logs/laravel.log`，搜索 "准备同步价格数据" 或 "准备同步库存数据" 关键字。

