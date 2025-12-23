# 携程订单接口检查报告

## 一、文档中定义的订单接口列表

根据 `storage/docs/trip-order.txt`，携程订单接口分为两类：

### A. 供应商需要实现的回调接口（携程调用供应商）

1. **VerifyOrder** - 订单验证
2. **CreatePreOrder** - 预下单创建
3. **PayPreOrder** - 预下单支付
4. **CancelPreOrder** - 预下单取消
5. **CreateOrder** - 订单新订
6. **CancelOrder** - 订单取消
7. **QueryOrder** - 订单查询
8. **RefundOrder** - 订单退款
9. **EditOrder** - 订单修改

### B. 供应商主动调用携程的接口（供应商调用携程）

1. **PayPreOrderConfirm** - 预下单支付确认
2. **CreateOrderConfirm** - 订单新订确认
3. **OrderTravelNotice** - 订单出行通知
4. **OrderConsumedNotice** - 订单核销通知
5. **CancelOrderConfirm** - 订单取消确认
6. **RefundOrderConfirm** - 订单退款确认
7. **EditOrderConfirm** - 订单修改确认
8. **SendVoucher** - 订单凭证发送

## 二、代码中已实现的接口

### 在 `CtripController@handleOrder` 中已实现：

1. ✅ **CreatePreOrder** / **PreCreateOrder** - `handlePreCreateOrder()`
2. ✅ **CreateOrder** - `handleCreateOrder()` 
3. ✅ **CancelOrder** - `handleCancelOrder()`
4. ✅ **QueryOrder** - `handleOrderQuery()`（已修正为 QueryOrder）
5. ⚠️ **OrderNotifyUsed** - `handleNotifyUsed()`（代码中引用但方法不存在）

## 三、缺失的接口

### 需要实现的回调接口：

1. ❌ **VerifyOrder** - 订单验证
2. ❌ **PayPreOrder** - 预下单支付
3. ❌ **CancelPreOrder** - 预下单取消
4. ❌ **RefundOrder** - 订单退款
5. ❌ **EditOrder** - 订单修改

### 需要实现的主动调用接口（在 CtripClient 中）：

1. ❌ **PayPreOrderConfirm** - 预下单支付确认
2. ❌ **CreateOrderConfirm** - 订单新订确认
3. ❌ **OrderTravelNotice** - 订单出行通知
4. ❌ **OrderConsumedNotice** - 订单核销通知
5. ❌ **CancelOrderConfirm** - 订单取消确认
6. ❌ **RefundOrderConfirm** - 订单退款确认
7. ❌ **EditOrderConfirm** - 订单修改确认
8. ❌ **SendVoucher** - 订单凭证发送

## 四、需要修正的问题

### 1. handleNotifyUsed 方法缺失

代码中引用了 `handleNotifyUsed` 方法，但该方法不存在。根据文档，应该是 **OrderConsumedNotice**（订单核销通知），但这是供应商主动调用携程的接口，不是回调接口。

**建议**：
- 如果 `OrderNotifyUsed` 是携程回调的接口，需要实现该方法
- 或者移除该引用，因为文档中没有 `OrderNotifyUsed` 这个回调接口

### 2. handleCreateOrder 接口混淆

当前 `handleCreateOrder` 方法处理的是预下单支付确认，但根据文档：
- **CreateOrder** 是订单新订（直接下单，不经过预下单流程）
- **PayPreOrder** 是预下单支付（预下单创建后的支付）

**建议**：
- 当前 `handleCreateOrder` 应该改为 `handlePayPreOrder`
- 如果需要支持直接下单流程，需要实现真正的 `handleCreateOrder`

### 3. handleCancelOrder 参数不完整

根据文档，`CancelOrder` 接口的请求参数应该包含：
- `sequenceId` - 携程处理批次流水号
- `otaOrderId` - 携程订单号
- `supplierOrderId` - 供应商订单号
- `confirmType` - 确认类型（1或2）
- `items` - 订单项数组，包含：
  - `itemId` - 订单项编号
  - `PLU` - 供应商产品最小单位
  - `quantity` - 本次请求取消份数
  - `cancelType` - 退订类型（0全退、1按份数部分退、2按出行人部分退）
  - `passengers` - 退订出行人节点（cancelType=2时必传）
  - `amount` - 被取消的退款金额
  - `amountCurrency` - 退款金额单位

当前实现只处理了简单的 `orderId` 和 `cancelQuantity`，不符合文档要求。

### 4. handlePreCreateOrder 错误码问题

- 库存不足时返回 `2003`（该订单已过期，不可退），应该返回 `1003`（库存不足）
- 错误信息格式不符合文档要求（文档要求返回具体的失败原因）

### 5. handleOrderQuery 响应格式问题

当前实现基本符合文档，但缺少：
- `passengers` 节点（出行人信息）
- `vouchers` 节点（凭证信息）

## 五、建议的修正方案

1. **补充缺失的回调接口方法**
2. **修正现有接口的参数解析和响应格式**
3. **实现供应商主动调用携程的接口（在 CtripClient 中）**
4. **修正错误码，使其符合文档要求**
5. **补充完整的参数验证和错误处理**

