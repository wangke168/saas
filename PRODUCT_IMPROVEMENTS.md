# 产品管理模块改进说明

## 概述

对产品管理模块进行了三项重要改进，提升了数据安全性、代码质量和可维护性。

## 改进内容

### 1. 添加软删除功能

**问题**：
- `Product` 模型没有使用软删除
- `orders` 表通过 `product_id` 关联了产品
- 如果产品被物理删除，历史订单将损坏或触发外键约束错误

**解决方案**：

#### 数据库迁移
- 创建迁移文件：`database/migrations/2025_12_22_014830_add_soft_deletes_to_products_table.php`
- 为 `products` 表添加 `deleted_at` 字段
- 支持回滚操作

#### 模型更新
- `Product` 模型：添加 `SoftDeletes` trait
- `Order` 模型：`product()` 关联使用 `withTrashed()`，确保历史订单可以访问已删除的产品信息

**效果**：
- 删除操作不会触发外键约束错误
- 历史订单可以正常访问已删除的产品信息
- 报表和财务数据不会受到影响

### 2. 使用 Policy 统一权限控制

**问题**：
- `isOperator` 的权限判断逻辑在 `index`, `show`, `store`, `update`, `destroy` 每个方法中都重复
- 代码冗余，难以维护

**解决方案**：

#### 创建 ProductPolicy
- 文件：`app/Policies/ProductPolicy.php`
- 统一管理所有产品相关的权限逻辑

#### Policy 方法
- `viewAny()`: 查看产品列表权限
- `view()`: 查看特定产品权限
- `create()`: 创建产品权限（支持传入景区ID检查）
- `update()`: 更新产品权限（支持检查景区变更权限）
- `delete()`: 删除产品权限
- `restore()`: 恢复产品权限
- `forceDelete()`: 永久删除权限

#### Controller 更新
- `show()`: 使用 `$this->authorize('view', $product)`
- `store()`: 使用 Policy 检查创建权限（传入景区ID）
- `update()`: 使用 Policy 检查更新权限（包括景区变更）
- `destroy()`: 使用 `$this->authorize('delete', $product)`

**效果**：
- 消除代码重复
- 权限逻辑集中管理
- 易于维护和测试
- 符合 Laravel 最佳实践

### 3. 统一服务层使用

**问题**：
- `store` 方法使用了 `ProductService->createProduct()`
- `update` 方法直接在 Controller 中调用了 `Product->update()`
- 服务层使用不一致

**解决方案**：

#### ProductService 增强
- 添加 `updateProduct()` 方法
- 使用数据库事务确保数据一致性
- 自动加载关联数据

#### Controller 更新
- `update()` 方法改为使用 `$this->productService->updateProduct()`
- 保持与 `store()` 方法的一致性

**效果**：
- 服务层使用统一
- 便于未来扩展（如缓存更新、通知发送等）
- 业务逻辑集中在服务层
- 便于单元测试

## 代码对比

### 改进前

```php
// Controller 中重复的权限检查
if ($request->user()->isOperator()) {
    $scenicSpotIds = $request->user()->scenicSpots->pluck('id');
    if (! $scenicSpotIds->contains($product->scenic_spot_id)) {
        return response()->json(['message' => '无权操作'], 403);
    }
}

// 直接在 Controller 中更新
$product->update($validated);
```

### 改进后

```php
// 使用 Policy 统一管理权限
$this->authorize('view', $product);
$policy = app(\App\Policies\ProductPolicy::class);
if (! $policy->create($request->user(), $validated['scenic_spot_id'])) {
    abort(403, '无权在该景区下创建产品');
}

// 使用服务层统一处理
$product = $this->productService->updateProduct($product, $validated);
```

## 使用说明

### 软删除操作

```php
// 软删除（设置 deleted_at）
$product->delete();

// 恢复软删除
$product->restore();

// 强制删除（真正删除，谨慎使用）
$product->forceDelete();

// 查询已删除的记录
$deletedProducts = Product::onlyTrashed()->get();

// 查询所有记录（包括已删除的）
$allProducts = Product::withTrashed()->get();
```

### Policy 使用

```php
// 在 Controller 中使用
$this->authorize('view', $product);
$this->authorize('delete', $product);

// 检查创建权限（传入景区ID）
$policy = app(\App\Policies\ProductPolicy::class);
if (! $policy->create($user, $scenicSpotId)) {
    abort(403, '无权创建');
}

// 检查更新权限（包括景区变更）
if (! $policy->update($user, $product, $newScenicSpotId)) {
    abort(403, '无权更新');
}
```

### 服务层使用

```php
// 创建产品
$product = $this->productService->createProduct($validated);

// 更新产品
$product = $this->productService->updateProduct($product, $validated);
```

## 迁移执行

执行迁移以添加软删除字段：

```bash
php artisan migrate
```

回滚迁移（如果需要）：

```bash
php artisan migrate:rollback
```

## 注意事项

1. **唯一索引**: 如果表中有唯一索引（如 `code` 字段），已删除的记录仍然会占用唯一值。如果需要重新使用已删除记录的编码，需要先恢复或强制删除该记录。

2. **级联删除**: `products` 表的外键设置了 `cascadeOnDelete()`，但这是针对硬删除的。软删除不会触发级联删除。

3. **性能考虑**: 软删除会增加查询的复杂度（需要检查 `deleted_at`），但影响很小。如果数据量很大，可以考虑定期清理已删除的记录。

4. **数据恢复**: 已删除的数据可以通过 `restore()` 方法恢复，但需要确保关联数据仍然有效。

5. **Policy 自动发现**: Laravel 11 会自动发现 Policy，无需手动注册。Policy 类名必须遵循 `ModelNamePolicy` 的命名规范。

## 测试建议

1. 测试删除已有订单的产品，应该成功（软删除）
2. 测试查询历史订单，应该能正常访问已删除的产品信息
3. 测试列表查询，应该不包含已删除的记录
4. 测试恢复已删除的记录，应该能正常恢复
5. 测试权限控制，运营用户只能管理自己绑定的景区下的产品
6. 测试服务层方法，确保创建和更新都使用服务层

## 总结

通过这三项改进，产品管理模块现在具有：
- ✅ 数据完整性保护（软删除）
- ✅ 统一的权限管理（Policy）
- ✅ 一致的服务层使用
- ✅ 更好的代码可维护性
- ✅ 符合 Laravel 最佳实践

