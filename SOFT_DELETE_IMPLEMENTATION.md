# 软删除功能实现说明

## 概述

为 `hotels` 和 `room_types` 表添加了软删除功能，避免删除已有订单的酒店或房型时出现数据完整性问题。

## 实现内容

### 1. 数据库迁移

**文件**: `database/migrations/2025_12_21_162814_add_soft_deletes_to_hotels_and_room_types_tables.php`

- 为 `hotels` 表添加 `deleted_at` 字段
- 为 `room_types` 表添加 `deleted_at` 字段
- 支持回滚操作

### 2. 模型更新

**Hotel 模型** (`app/Models/Hotel.php`)
- 添加 `SoftDeletes` trait
- 自动排除已删除的记录（默认行为）

**RoomType 模型** (`app/Models/RoomType.php`)
- 添加 `SoftDeletes` trait
- 自动排除已删除的记录（默认行为）

**Order 模型** (`app/Models/Order.php`)
- `hotel()` 关联使用 `withTrashed()`，允许访问已删除的酒店（用于历史订单查询）
- `roomType()` 关联使用 `withTrashed()`，允许访问已删除的房型（用于历史订单查询）

## 功能特点

### 1. 自动排除已删除记录

使用 `SoftDeletes` trait 后，所有查询会自动排除 `deleted_at` 不为空的记录：

```php
// 只返回未删除的酒店
$hotels = Hotel::all();

// 只返回未删除的房型
$roomTypes = RoomType::where('hotel_id', $hotelId)->get();
```

### 2. 历史订单数据完整性

订单关联的酒店和房型使用 `withTrashed()`，确保历史订单可以正常访问已删除的酒店和房型信息：

```php
// 即使酒店已删除，订单仍能访问酒店信息
$order = Order::with('hotel', 'roomType')->find($id);
// $order->hotel 和 $order->roomType 仍然可用
```

### 3. 软删除操作

删除操作不会真正删除数据，只是标记为已删除：

```php
// 软删除（设置 deleted_at）
$hotel->delete();

// 恢复软删除
$hotel->restore();

// 强制删除（真正删除）
$hotel->forceDelete();
```

### 4. 查询已删除的记录

如果需要查询已删除的记录：

```php
// 只查询已删除的记录
$deletedHotels = Hotel::onlyTrashed()->get();

// 查询所有记录（包括已删除的）
$allHotels = Hotel::withTrashed()->get();
```

## 权限控制

软删除功能不影响现有的权限控制逻辑：

- 运营用户只能删除自己绑定的景区下的酒店和房型
- 超级管理员可以删除所有酒店和房型
- 删除操作会进行权限检查

## 数据完整性保护

### 问题解决

**之前的问题**:
- 如果尝试删除已有订单的酒店，数据库会报错（Integrity Constraint Violation）
- 如果强制删除，历史订单数据将失去关联信息

**现在的解决方案**:
- 删除操作只是标记为已删除，不会触发外键约束错误
- 历史订单可以正常访问已删除的酒店和房型信息
- 报表和财务数据不会受到影响

### 关联数据

以下关联会自动处理软删除：

- `Hotel::roomTypes()` - 只返回未删除的房型
- `RoomType::hotel()` - 如果酒店已删除，关联会返回 null（除非使用 withTrashed()）
- `Order::hotel()` - 使用 `withTrashed()`，可以访问已删除的酒店
- `Order::roomType()` - 使用 `withTrashed()`，可以访问已删除的房型

## 使用建议

### 1. 列表查询

列表查询会自动排除已删除的记录，无需特殊处理：

```php
// 控制器中的查询逻辑无需修改
$hotels = Hotel::with(['scenicSpot', 'resourceProvider'])->get();
```

### 2. 关联查询

使用 `whereHas` 查询时，会自动排除已删除的关联记录：

```php
// 只查询未删除酒店的房型
$roomTypes = RoomType::whereHas('hotel', function ($q) {
    $q->where('is_active', true);
})->get();
```

### 3. 历史数据查询

查询历史订单时，可以正常访问已删除的酒店和房型：

```php
// 订单查询会自动包含已删除的酒店和房型
$orders = Order::with(['hotel', 'roomType'])->get();
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

2. **级联删除**: `hotels` 表的外键设置了 `cascadeOnDelete()`，但这是针对硬删除的。软删除不会触发级联删除。

3. **性能考虑**: 软删除会增加查询的复杂度（需要检查 `deleted_at`），但影响很小。如果数据量很大，可以考虑定期清理已删除的记录。

4. **数据恢复**: 已删除的数据可以通过 `restore()` 方法恢复，但需要确保关联数据仍然有效。

## 测试建议

1. 测试删除已有订单的酒店，应该成功（软删除）
2. 测试查询历史订单，应该能正常访问已删除的酒店和房型信息
3. 测试列表查询，应该不包含已删除的记录
4. 测试恢复已删除的记录，应该能正常恢复

