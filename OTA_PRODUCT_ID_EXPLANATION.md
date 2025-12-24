# OTA Product ID 字段说明

## 字段位置
- **表名**: `ota_products`
- **字段名**: `ota_product_id`
- **类型**: `string` (nullable)
- **注释**: OTA平台产品ID

## 字段作用

### 1. 存储携程资源编号（otaOptionId）

`ota_product_id` 字段用于存储 **携程平台分配的资源编号**（`otaOptionId`）。

根据携程接口文档，在调用价格和库存同步接口时，可以使用两种方式标识产品：
- **otaOptionId**（携程资源编号）：由携程平台分配的数字ID
- **supplierOptionId**（供应商PLU）：供应商自己定义的编码

### 2. 优先级逻辑

在 `CtripService::buildProductIdentifier()` 方法中，实现了以下优先级：

```php
// 优先级1: 如果存在 ota_product_id（携程资源编号），优先使用
if ($otaProduct && !empty($otaProduct->ota_product_id)) {
    return ['otaOptionId' => (int)$otaProduct->ota_product_id];
}

// 优先级2: 使用 supplierOptionId（组合格式：酒店编码|房型编码|产品编码）
if ($ctripProductCode) {
    return ['supplierOptionId' => trim($ctripProductCode)];
}

// 优先级3: 使用产品编码
if (!empty($product->code)) {
    return ['supplierOptionId' => trim((string)$product->code)];
}
```

### 3. 使用场景

#### 场景A：使用 otaOptionId（推荐）
- **前提**: 携程平台已分配资源编号
- **操作**: 将携程分配的资源编号存储在 `ota_product_id` 字段
- **优势**: 
  - 更稳定，由携程平台管理
  - 避免 `supplierOptionId` 不存在的问题
  - 符合携程推荐做法

#### 场景B：使用 supplierOptionId
- **前提**: 携程平台未分配资源编号，或使用供应商自定义编码
- **操作**: `ota_product_id` 字段为空，使用 `supplierOptionId`
- **注意**: 
  - 需要在携程平台先创建/注册该产品选项
  - 否则会返回错误：`supplierOptionId not exist or its value is empty`

### 4. 当前实现

查看 `OtaProductController::pushToCtrip()` 方法：

```php
// 当前实现：推送成功后，ota_product_id 设置为 'CTRIP_' . $product->id
'ota_product_id' => 'CTRIP_' . $product->id,
```

**问题**: 这个值不是真正的携程资源编号，只是一个占位符。

### 5. 如何获取真正的携程资源编号

#### 方法1：从携程平台获取
1. 登录携程开放平台（https://ttdopen.ctrip.com）
2. 在产品管理页面查看已创建的产品
3. 获取对应的资源编号（otaOptionId）
4. 手动更新到 `ota_products` 表的 `ota_product_id` 字段

#### 方法2：从携程API响应中获取
如果携程在创建产品时返回了资源编号，可以在推送成功后保存：

```php
// 在 pushToCtrip() 方法中
$pushResult = $this->ctripService->syncProductPriceByCombo(...);
if ($pushResult['success'] && isset($pushResult['otaOptionId'])) {
    $otaProduct->update([
        'ota_product_id' => $pushResult['otaOptionId'],
    ]);
}
```

#### 方法3：从携程回调中获取
如果携程在订单回调或其他通知中返回了资源编号，可以更新：

```php
// 在 CtripController 中处理回调
if (isset($body['otaOptionId'])) {
    $otaProduct = OtaProduct::where('product_id', $productId)
        ->where('ota_platform_id', $ctripPlatformId)
        ->first();
    
    if ($otaProduct && empty($otaProduct->ota_product_id)) {
        $otaProduct->update([
            'ota_product_id' => $body['otaOptionId'],
        ]);
    }
}
```

### 6. 解决当前错误的方法

当前错误：`supplierOptionId not exist or its value is empty`

**解决方案**：

1. **方案A（推荐）**: 使用 otaOptionId
   - 在携程平台获取资源编号
   - 更新 `ota_products` 表的 `ota_product_id` 字段
   - 代码会自动优先使用 `otaOptionId`

2. **方案B**: 在携程平台创建产品选项
   - 使用 `supplierOptionId` 格式：`酒店编码|房型编码|产品编码`
   - 在携程平台先创建/注册该产品选项
   - 然后才能推送价格和库存

3. **方案C**: 使用单个产品编码
   - 根据测试文档，产品编码可能是单个编码（如 `0001`、`1001`）
   - 而不是组合格式 `1|001|123`
   - 需要确认携程平台支持哪种格式

### 7. 数据库查询示例

```sql
-- 查看所有 OTA 产品及其资源编号
SELECT 
    op.id,
    p.name AS product_name,
    p.code AS product_code,
    oap.name AS ota_platform_name,
    op.ota_product_id,
    op.is_active,
    op.pushed_at
FROM ota_products op
JOIN products p ON op.product_id = p.id
JOIN ota_platforms oap ON op.ota_platform_id = oap.id
WHERE oap.code = 'ctrip';

-- 查看没有资源编号的产品
SELECT 
    op.id,
    p.name AS product_name,
    p.code AS product_code,
    op.ota_product_id
FROM ota_products op
JOIN products p ON op.product_id = p.id
JOIN ota_platforms oap ON op.ota_platform_id = oap.id
WHERE oap.code = 'ctrip' 
  AND (op.ota_product_id IS NULL OR op.ota_product_id = '');
```

### 8. 总结

- **ota_product_id** 字段用于存储携程平台分配的资源编号（otaOptionId）
- 如果该字段有值，代码会优先使用 `otaOptionId`，避免 `supplierOptionId` 不存在的问题
- 如果该字段为空，代码会使用 `supplierOptionId`，但需要在携程平台先创建产品选项
- 当前实现中，该字段只是一个占位符，需要手动更新为真正的携程资源编号

