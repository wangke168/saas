<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\ExceptionOrderType;
use App\Enums\OrderStatus;
use App\Enums\OtaPlatform;
use App\Http\Controllers\Controller;
use App\Http\Client\MeituanClient;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Services\OrderProcessorService;
use App\Services\OrderService;
use App\Services\OrderOperationService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeituanController extends Controller
{
    protected ?MeituanClient $client = null;

    public function __construct(
        protected OrderService $orderService,
        protected OrderProcessorService $orderProcessorService,
        protected InventoryService $inventoryService,
        protected OrderOperationService $orderOperationService
    ) {}

    /**
     * 获取美团客户端
     */
    protected function getClient(): ?MeituanClient
    {
        if ($this->client === null) {
            // 优先使用环境变量配置（如果存在）
            $config = $this->createConfigFromEnv();
            
            // 如果环境变量配置不存在，尝试从数据库读取
            if (!$config) {
                $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
                $config = $platform?->config;
            }

            if (!$config) {
                return null;
            }

            $this->client = new MeituanClient($config);
        }

        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?\App\Models\OtaConfig
    {
        // 检查环境变量是否存在
        if (!env('MEITUAN_PARTNER_ID') || !env('MEITUAN_APP_KEY') || !env('MEITUAN_APP_SECRET')) {
            return null;
        }

        // 创建临时配置对象（不保存到数据库）
        $config = new \App\Models\OtaConfig();
        $config->account = env('MEITUAN_PARTNER_ID'); // PartnerId存储在account字段
        $config->secret_key = env('MEITUAN_APP_KEY'); // AppKey存储在secret_key字段
        $config->aes_key = env('MEITUAN_APP_SECRET'); // AppSecret存储在aes_key字段
        $config->aes_iv = env('MEITUAN_AES_KEY', ''); // AES密钥存储在aes_iv字段
        
        // API URL 配置
        $config->api_url = env('MEITUAN_API_URL', 'https://openapi.meituan.com');
        $config->callback_url = env('MEITUAN_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 美团订单回调接口（统一入口）
     * 根据请求路径或参数判断具体接口类型
     */
    public function handleOrder(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 美团请求格式：请求体是JSON，包含body字段（加密的字符串）
            $requestData = $request->all();
            $encryptedBody = $requestData['body'] ?? '';

            if (empty($encryptedBody)) {
                Log::error('美团订单回调：请求体为空', [
                    'request_data' => $requestData,
                ]);
                return $this->errorResponse(400, '请求体为空');
            }

            // 解密body字段
            try {
                $decryptedBody = $client->decryptBody($encryptedBody);
                $data = json_decode($decryptedBody, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('美团订单回调：JSON解析失败', [
                        'error' => json_last_error_msg(),
                        'decrypted_body' => $decryptedBody,
                    ]);
                    return $this->errorResponse(400, '请求数据格式错误');
                }

                Log::info('美团订单回调解密后数据', [
                    'data' => $data,
                ]);
            } catch (\Exception $e) {
                Log::error('美团订单回调：解密失败', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return $this->errorResponse(400, '请求数据解密失败');
            }

            // 根据请求路径判断接口类型
            $path = $request->path();
            $body = $data['body'] ?? $data;

            // 判断接口类型（优先根据路径判断）
            if (str_contains($path, 'order/create/v2')) {
                return $this->handleOrderCreateV2($data);
            } elseif (str_contains($path, 'order/pay')) {
                return $this->handleOrderPay($data);
            } elseif (str_contains($path, 'order/query')) {
                return $this->handleOrderQuery($data);
            } elseif (str_contains($path, 'order/refund') && !str_contains($path, 'refunded')) {
                return $this->handleOrderRefund($data);
            } elseif (str_contains($path, 'order/refunded')) {
                return $this->handleOrderRefunded($data);
            } elseif (str_contains($path, 'order/close')) {
                return $this->handleOrderClose($data);
            } else {
                // 如果路径无法判断，根据数据内容判断
                if (isset($body['closeType'])) {
                    return $this->handleOrderClose($data);
                } elseif (isset($body['refundSerialNo']) && isset($body['refundTime'])) {
                    return $this->handleOrderRefunded($data);
                } elseif (isset($body['refundSerialNo'])) {
                    return $this->handleOrderRefund($data);
                } elseif (isset($body['orderId']) && isset($body['payTime'])) {
                    return $this->handleOrderPay($data);
                } elseif (isset($body['orderId'])) {
                    // 可能是订单创建或订单查询，需要进一步判断
                    // 订单创建通常有partnerDealId和quantity
                    if (isset($body['partnerDealId']) && isset($body['quantity'])) {
                        return $this->handleOrderCreateV2($data);
                    } else {
                        return $this->handleOrderQuery($data);
                    }
                } else {
                    Log::warning('美团订单回调：未知接口类型', [
                        'path' => $path,
                        'data' => $data,
                    ]);
                    return $this->errorResponse(400, '未知接口类型');
                }
            }
        } catch (\Exception $e) {
            Log::error('美团订单回调异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse(500, '系统处理异常');
        }
    }

    /**
     * 返回错误响应
     */
    protected function errorResponse(int $code, string $message): JsonResponse
    {
        $client = $this->getClient();
        $encryptedBody = '';

        if ($client) {
            try {
                $body = [
                    'code' => $code,
                    'describe' => $message,
                ];
                $encryptedBody = $client->encryptBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (\Exception $e) {
                Log::error('美团响应加密失败', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'code' => $code,
            'describe' => $message,
            'body' => $encryptedBody,
        ]);
    }

    /**
     * 返回成功响应
     */
    protected function successResponse(array $body = []): JsonResponse
    {
        $client = $this->getClient();
        $encryptedBody = '';

        if ($client) {
            try {
                // 美团响应格式：body字段是加密的JSON字符串
                // 如果body为空，返回空字符串
                if (!empty($body)) {
                    $encryptedBody = $client->encryptBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            } catch (\Exception $e) {
                Log::error('美团响应加密失败', [
                    'error' => $e->getMessage(),
                    'body' => $body,
                ]);
            }
        }

        return response()->json([
            'code' => 200,
            'describe' => 'success',
            'body' => $encryptedBody,
        ]);
    }

    /**
     * 处理订单创建V2（对应携程的CreatePreOrder）
     * 需要锁定库存
     */
    protected function handleOrderCreateV2(array $data): JsonResponse
    {
        try {
            DB::beginTransaction();

            $otaPlatform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$otaPlatform) {
                DB::rollBack();
                return $this->errorResponse(500, 'OTA平台配置不存在');
            }

            // 解析请求数据
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $partnerDealId = $body['partnerDealId'] ?? '';
            $quantity = intval($body['quantity'] ?? 1);
            $useDate = $body['useDate'] ?? '';
            $realNameType = intval($body['realNameType'] ?? 0);
            $credentialList = $body['credentialList'] ?? [];
            $contacts = $body['contacts'] ?? [];
            $contactInfo = !empty($contacts) ? $contacts[0] : [];

            // 验证必要参数
            if (empty($orderId)) {
                DB::rollBack();
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            if (empty($partnerDealId)) {
                DB::rollBack();
                return $this->errorResponse(400, '产品编码(partnerDealId)为空');
            }

            if (empty($useDate)) {
                DB::rollBack();
                return $this->errorResponse(400, '使用日期(useDate)为空');
            }

            // 根据产品编码查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                DB::rollBack();
                Log::error('美团订单创建V2：产品不存在', [
                    'partner_deal_id' => $partnerDealId,
                ]);
                return $this->errorResponse(505, '产品不存在');
            }

            // 查找产品关联的酒店和房型（通过价格表）
            $price = $product->prices()->where('date', $useDate)->first();
            if (!$price) {
                DB::rollBack();
                Log::error('美团订单创建V2：指定日期没有价格', [
                    'product_id' => $product->id,
                    'use_date' => $useDate,
                ]);
                return $this->errorResponse(400, '指定日期没有价格');
            }

            $roomType = $price->roomType;
            $hotel = $roomType->hotel ?? null;

            if (!$hotel || !$roomType) {
                DB::rollBack();
                Log::error('美团订单创建V2：产品未关联酒店或房型', [
                    'product_id' => $product->id,
                ]);
                return $this->errorResponse(400, '产品未关联酒店或房型');
            }

            // 检查库存（考虑入住天数）
            $stayDays = $product->stay_days ?: 1;
            $checkInDate = \Carbon\Carbon::parse($useDate);
            
            // 检查连续入住天数的库存是否足够
            $inventoryCheck = $this->checkInventoryForStayDays($roomType->id, $checkInDate, $stayDays, $quantity);
            if (!$inventoryCheck['success']) {
                DB::rollBack();
                return $this->errorResponse(503, $inventoryCheck['message']);
            }

            // 检查是否已存在订单（防止重复）
            $existingOrder = Order::where('ota_order_no', (string)$orderId)
                ->where('ota_platform_id', $otaPlatform->id)
                ->first();

            if ($existingOrder) {
                // 已存在，返回成功（幂等性）
                DB::rollBack();
                Log::info('美团订单创建V2：订单已存在，返回成功', [
                    'order_id' => $orderId,
                    'order_no' => $existingOrder->order_no,
                ]);
                return $this->successResponse([
                    'orderId' => intval($orderId),
                    'partnerOrderId' => $existingOrder->order_no,
                ]);
            }

            // 计算价格
            $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                $product,
                $roomType->id,
                $useDate
            );
            $salePrice = floatval($priceData['sale_price']);
            $settlementPrice = floatval($priceData['settlement_price']);

            // 处理联系人信息
            $contactName = $contactInfo['name'] ?? '';
            $contactPhone = $contactInfo['mobile'] ?? $contactInfo['phone'] ?? '';
            $contactEmail = $contactInfo['email'] ?? '';

            // 处理实名制订单
            $credentialListData = null;
            if ($realNameType === 1 && !empty($credentialList)) {
                $credentialListData = [];
                foreach ($credentialList as $credential) {
                    $credentialListData[] = [
                        'credentialType' => intval($credential['credentialType'] ?? 0),
                        'credentialNo' => $credential['credentialNo'] ?? '',
                        'voucher' => $credential['voucher'] ?? '',
                        'status' => 0, // 0=未使用
                    ];
                }
            }

            // 计算离店日期（根据产品入住天数）
            $stayDays = $product->stay_days ?: 1;
            $checkOutDate = \Carbon\Carbon::parse($useDate)->addDays($stayDays)->format('Y-m-d');
            
            Log::info('美团订单创建V2：根据产品入住天数计算离店日期', [
                'use_date' => $useDate,
                'stay_days' => $stayDays,
                'calculated_check_out_date' => $checkOutDate,
            ]);

            // 创建订单
            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'ota_order_no' => (string)$orderId,
                'ota_platform_id' => $otaPlatform->id,
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'status' => OrderStatus::PAID_PENDING,
                'check_in_date' => $useDate,
                'check_out_date' => $checkOutDate,
                'room_count' => $quantity,
                'guest_count' => $quantity, // 默认等于房间数
                'contact_name' => $contactName,
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail,
                'guest_info' => $credentialList ?? [],
                'real_name_type' => $realNameType,
                'credential_list' => $credentialListData,
                'total_amount' => intval($salePrice * $quantity * 100), // 转换为分
                'settlement_amount' => intval($settlementPrice * $quantity * 100), // 转换为分
                'paid_at' => null, // 订单创建时还未支付
            ]);

            // 锁定库存（订单创建的核心目的就是锁库存）
            $lockResult = $this->lockInventoryForPreOrder($order, $product->stay_days);
            if (!$lockResult['success']) {
                DB::rollBack();
                Log::error('美团订单创建V2：库存锁定失败', [
                    'order_id' => $order->id,
                    'error' => $lockResult['message'],
                ]);
                return $this->errorResponse(503, '库存锁定失败：' . $lockResult['message']);
            }

            DB::commit();

            Log::info('美团订单创建V2成功', [
                'order_id' => $orderId,
                'order_no' => $order->order_no,
                'partner_deal_id' => $partnerDealId,
            ]);

            return $this->successResponse([
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('美团订单创建V2失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常：' . $e->getMessage());
        }
    }

    /**
     * 处理订单出票（对应携程的PayPreOrder）
     */
    protected function handleOrderPay(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $partnerOrderId = $body['partnerOrderId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 如果订单已经是确认状态，直接返回成功（幂等性）
            if ($order->status === OrderStatus::CONFIRMED) {
                return $this->buildOrderPaySuccessResponse($order, $orderId);
            }

            // 检查订单状态（必须是PAID_PENDING）
            if ($order->status !== OrderStatus::PAID_PENDING) {
                return $this->errorResponse(506, '订单状态不正确，当前状态：' . $order->status->label());
            }

            // 更新支付时间
            if (!$order->paid_at) {
                $order->update(['paid_at' => now()]);
            }

            // 检查是否系统直连
            $scenicSpot = $order->hotel->scenicSpot ?? null;
            $isSystemConnected = $scenicSpot && $scenicSpot->is_system_connected;

            // 先响应美团（不等待景区方接口）
            // 系统直连和非系统直连都返回 code=598（出票中）
            $code = 598; // 出票中

            // 异步处理景区方接口调用
            if ($isSystemConnected) {
                // 系统直连：异步调用景区方接口接单（设置 10 秒超时）
                \App\Jobs\ProcessResourceOrderJob::dispatch($order, 'confirm')
                    ->timeout(10);
            } else {
                // 非系统直连：只更新状态为确认中，等待人工接单
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::CONFIRMING,
                    '美团订单出票，等待人工接单'
                );
            }

            // 返回出票中响应
            return $this->successResponse([
                'code' => $code,
                'describe' => '出票中',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单出票处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 构建订单出票成功响应
     */
    protected function buildOrderPaySuccessResponse(Order $order, string $orderId): JsonResponse
    {
        // 构建响应数据
        $responseBody = [
            'code' => 200,
            'describe' => 'success',
            'orderId' => intval($orderId),
            'partnerOrderId' => $order->order_no,
            'voucherType' => 0, // 不需要支持一码一验，统一使用0
            'realNameType' => $order->real_name_type ?? 0,
        ];

        // 如果订单有凭证码，返回凭证码（这里暂时返回空，实际应该从订单中获取）
        $responseBody['vouchers'] = [];
        $responseBody['voucherPics'] = [];
        $responseBody['voucherAdditionalList'] = [];

        // 如果是实名制订单，返回credentialList
        if ($order->real_name_type === 1 && !empty($order->credential_list)) {
            $responseBody['credentialList'] = [];
            foreach ($order->credential_list as $credential) {
                $responseBody['credentialList'][] = [
                    'credentialType' => $credential['credentialType'] ?? 0,
                    'credentialNo' => $credential['credentialNo'] ?? '',
                    'voucher' => $credential['voucher'] ?? '',
                ];
            }
        }

        return $this->successResponse($responseBody);
    }

    /**
     * 处理订单查询（对应携程的QueryOrder）
     */
    protected function handleOrderQuery(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)
                ->with(['product', 'hotel', 'roomType'])
                ->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 映射订单状态到美团状态
            $orderStatus = $this->mapOrderStatusToMeituan($order->status);

            // 构建响应数据
            $responseBody = [
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
                'orderStatus' => $orderStatus,
                'partnerDealId' => $order->product->code ?? '',
                'quantity' => $order->room_count,
                'useDate' => $order->check_in_date->format('Y-m-d'),
                'totalPrice' => floatval($order->total_amount) / 100, // 转换为元
                'settlementPrice' => floatval($order->settlement_amount) / 100, // 转换为元
            ];

            // 如果订单已核销，返回usedQuantity
            if ($order->status === OrderStatus::VERIFIED) {
                $responseBody['usedQuantity'] = $order->room_count;
            }

            // 如果订单已退款，返回refundedQuantity
            if ($order->status === OrderStatus::CANCEL_APPROVED) {
                $responseBody['refundedQuantity'] = $order->room_count;
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团订单查询失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 映射订单状态到美团状态
     */
    protected function mapOrderStatusToMeituan(OrderStatus $status): int
    {
        return match($status) {
            OrderStatus::PAID_PENDING => 2, // 创建订单成功
            OrderStatus::REJECTED => 3, // 创建订单失败
            OrderStatus::CONFIRMED => 4, // 出票成功（用户可以入园）
            OrderStatus::CONFIRMING => 5, // 出票中（向上游二次直连时使用）
            OrderStatus::VERIFIED => 4, // 已使用（通过usedQuantity判断）
            OrderStatus::CANCEL_APPROVED => 4, // 已退款（通过refundedQuantity判断）
            default => 2,
        };
    }

    /**
     * 处理订单退款（对应携程的CancelOrder）
     */
    protected function handleOrderRefund(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $refundQuantity = intval($body['refundQuantity'] ?? 0);

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 检查订单状态（必须是PAID_PENDING或CONFIRMED）
            if (!in_array($order->status, [OrderStatus::PAID_PENDING, OrderStatus::CONFIRMED])) {
                return $this->errorResponse(506, '订单状态不允许退款，当前状态：' . $order->status->label());
            }

            // 如果订单已使用，不允许退款
            if ($order->status === OrderStatus::VERIFIED) {
                return $this->errorResponse(506, '订单已使用，不允许退款');
            }

            // 如果订单已过期，不允许退款
            if ($order->check_in_date < now()->toDateString()) {
                return $this->errorResponse(506, '订单已过期，不允许退款');
            }

            // 验证退款数量
            if ($refundQuantity <= 0 || $refundQuantity > $order->room_count) {
                return $this->errorResponse(400, '退款数量不正确');
            }

            // 检查是否系统直连
            $scenicSpot = $order->hotel->scenicSpot ?? null;
            $isSystemConnected = $scenicSpot && $scenicSpot->is_system_connected;

            // 先响应美团（不等待景区方接口）
            // 系统直连和非系统直连都返回 code=602（审批中）
            $code = 602; // 审批中

            // 异步处理景区方接口调用
            if ($isSystemConnected) {
                // 系统直连：异步调用景区方接口取消订单（设置 10 秒超时）
                \App\Jobs\ProcessResourceCancelOrderJob::dispatch($order, '美团申请退款')
                    ->timeout(10);
            } else {
                // 非系统直连：只更新状态为申请取消中，等待人工处理
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::CANCEL_REQUESTED,
                    '美团申请退款，数量：' . $refundQuantity
                );
            }

            // 返回审批中响应
            return $this->successResponse([
                'code' => $code,
                'describe' => '退款审批中',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单退款处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理已退款消息（新功能）
     */
    protected function handleOrderRefunded(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $refundSerialNo = $body['refundSerialNo'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 幂等性检查：如果订单已存在退款流水号且与请求中的相同，直接返回成功
            if ($order->refund_serial_no && $order->refund_serial_no === $refundSerialNo) {
                return $this->successResponse([
                    'code' => 200,
                    'describe' => 'success',
                    'orderId' => intval($orderId),
                ]);
            }

            // 更新订单状态为CANCEL_APPROVED
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                '美团已退款，退款流水号：' . $refundSerialNo
            );

            // 记录退款流水号
            $order->update(['refund_serial_no' => $refundSerialNo]);

            // 释放库存
            $releaseResult = $this->releaseInventoryForPreOrder($order);
            if (!$releaseResult['success']) {
                Log::warning('美团已退款消息：库存释放失败', [
                    'order_id' => $order->id,
                    'error' => $releaseResult['message'],
                ]);
            }

            return $this->successResponse([
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
            ]);

        } catch (\Exception $e) {
            Log::error('美团已退款消息处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理订单关闭消息（新功能）
     */
    protected function handleOrderClose(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $closeType = intval($body['closeType'] ?? 0);

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 幂等性检查：如果订单状态已经是CANCEL_APPROVED，直接返回成功
            if ($order->status === OrderStatus::CANCEL_APPROVED) {
                return $this->successResponse([
                    'code' => 200,
                    'describe' => 'success',
                    'orderId' => intval($orderId),
                ]);
            }

            // 更新订单状态为CANCEL_APPROVED
            $closeTypeMap = [
                1 => '用户未支付，美团关闭订单',
                2 => '合作方下单接口异常，美团关闭订单',
                3 => '合作方出票接口异常，美团出票失败且已退款',
            ];
            $closeReason = $closeTypeMap[$closeType] ?? '订单关闭';

            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                $closeReason . '，closeType=' . $closeType
            );

            // 记录closeType到订单日志
            \App\Models\OrderLog::create([
                'order_id' => $order->id,
                'from_status' => $order->status->value,
                'to_status' => OrderStatus::CANCEL_APPROVED->value,
                'remark' => $closeReason . '，closeType=' . $closeType,
            ]);

            // 释放库存
            $releaseResult = $this->releaseInventoryForPreOrder($order);
            if (!$releaseResult['success']) {
                Log::warning('美团订单关闭消息：库存释放失败', [
                    'order_id' => $order->id,
                    'error' => $releaseResult['message'],
                ]);
            }

            return $this->successResponse([
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单关闭消息处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 释放库存（预下单）
     */
    protected function releaseInventoryForPreOrder(Order $order): array
    {
        try {
            // 获取入住天数
            $product = $order->product;
            $stayDays = $product->stay_days ?: 1;

            // 获取日期范围
            $dates = $this->inventoryService->getDateRange($order->check_in_date, $stayDays);

            // 使用统一的库存服务释放库存
            $success = $this->inventoryService->releaseInventoryForDates(
                $order->room_type_id,
                $dates,
                $order->room_count
            );

            if ($success) {
                return [
                    'success' => true,
                    'message' => '库存释放成功',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '库存释放失败：系统异常',
                ];
            }
        } catch (\Exception $e) {
            Log::error('预下单库存释放异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '库存释放失败：系统异常',
            ];
        }
    }

    /**
     * 锁定库存（预下单）
     */
    protected function lockInventoryForPreOrder(Order $order, ?int $stayDays = null): array
    {
        try {
            // 获取入住天数
            if ($stayDays === null) {
                $product = $order->product;
                $stayDays = $product->stay_days ?: 1;
            }

            // 获取日期范围
            $dates = $this->inventoryService->getDateRange($order->check_in_date, $stayDays);

            // 使用统一的库存服务锁定库存
            $success = $this->inventoryService->lockInventoryForDates(
                $order->room_type_id,
                $dates,
                $order->room_count
            );

            if ($success) {
                return [
                    'success' => true,
                    'message' => '库存锁定成功',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '库存锁定失败：库存不足或并发冲突',
                ];
            }
        } catch (\Exception $e) {
            Log::error('预下单库存锁定异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '库存锁定失败：系统异常',
            ];
        }
    }

    /**
     * 检查连续入住天数的库存
     */
    protected function checkInventoryForStayDays(int $roomTypeId, \Carbon\Carbon $checkInDate, int $stayDays, int $quantity): array
    {
        $dates = $this->inventoryService->getDateRange($checkInDate->format('Y-m-d'), $stayDays);

        foreach ($dates as $date) {
            $inventory = \App\Models\Inventory::where('room_type_id', $roomTypeId)
                ->where('date', $date)
                ->first();

            if (!$inventory || $inventory->is_closed || $inventory->available_quantity < $quantity) {
                return [
                    'success' => false,
                    'message' => "日期 {$date} 库存不足或已关闭",
                ];
            }
        }

        return ['success' => true];
    }

    /**
     * 生成订单号
     */
    protected function generateOrderNo(): string
    {
        return 'ORD' . date('YmdHis') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * 处理拉取价格日历（美团主动拉取）
     */
    public function handleProductPriceCalendar(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 解密请求体
            $encryptedBody = $request->input('body', '');
            if (empty($encryptedBody)) {
                return $this->errorResponse(400, '请求体为空');
            }

            $decryptedBody = $client->decryptBody($encryptedBody);
            $data = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse(400, '请求数据格式错误');
            }

            $body = $data['body'] ?? $data;
            $partnerDealId = $body['partnerDealId'] ?? '';
            $startTime = $body['startTime'] ?? '';
            $endTime = $body['endTime'] ?? '';

            if (empty($partnerDealId) || empty($startTime) || empty($endTime)) {
                return $this->errorResponse(400, '参数不完整');
            }

            // 查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                return $this->errorResponse(505, '产品不存在');
            }

            // 获取价格数据
            $prices = $product->prices()
                ->whereBetween('date', [$startTime, $endTime])
                ->with('roomType')
                ->get();

            $responseBody = [];
            foreach ($prices as $price) {
                $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                    $product,
                    $price->room_type_id,
                    $price->date->format('Y-m-d')
                );

                // 获取库存
                $inventory = \App\Models\Inventory::where('room_type_id', $price->room_type_id)
                    ->where('date', $price->date)
                    ->first();

                $stock = 0;
                if ($inventory && !$inventory->is_closed) {
                    // 检查销售日期范围
                    $isInSalePeriod = true;
                    if ($product->sale_start_date || $product->sale_end_date) {
                        $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                        $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                        $date = $price->date->format('Y-m-d');
                        
                        if ($saleStartDate && $date < $saleStartDate) {
                            $isInSalePeriod = false;
                        }
                        if ($saleEndDate && $date > $saleEndDate) {
                            $isInSalePeriod = false;
                        }
                    }
                    
                    if ($isInSalePeriod) {
                        $stock = $inventory->available_quantity;
                    }
                }

                $responseBody[] = [
                    'partnerDealId' => $partnerDealId,
                    'date' => $price->date->format('Y-m-d'),
                    'mtPrice' => floatval($priceData['sale_price']),
                    'marketPrice' => floatval($priceData['market_price'] ?? $priceData['sale_price']),
                    'settlementPrice' => floatval($priceData['settlement_price']),
                    'stock' => $stock,
                ];
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团拉取价格日历失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理拉取多层价格日历V2（美团主动拉取）
     */
    public function handleProductLevelPriceCalendarV2(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 解密请求体
            $encryptedBody = $request->input('body', '');
            if (empty($encryptedBody)) {
                return $this->errorResponse(400, '请求体为空');
            }

            $decryptedBody = $client->decryptBody($encryptedBody);
            $data = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse(400, '请求数据格式错误');
            }

            $body = $data['body'] ?? $data;
            $partnerDealId = $body['partnerDealId'] ?? '';
            $startTime = $body['startTime'] ?? '';
            $endTime = $body['endTime'] ?? '';
            $asyncType = intval($body['asyncType'] ?? 0); // 0=同步，1=异步

            if (empty($partnerDealId) || empty($startTime) || empty($endTime)) {
                return $this->errorResponse(400, '参数不完整');
            }

            // 查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                return $this->errorResponse(505, '产品不存在');
            }

            // 如果异步拉取，返回code=999，然后通过"多层价格日历变化通知V2"推送
            if ($asyncType === 1) {
                // TODO: 触发异步推送任务
                return $this->successResponse([
                    'code' => 999,
                    'describe' => '异步拉取，将通过通知接口推送',
                ]);
            }

            // 同步拉取：直接返回价格日历数据
            // 获取产品的所有"产品-酒店-房型"组合
            $prices = $product->prices()
                ->whereBetween('date', [$startTime, $endTime])
                ->with(['roomType.hotel'])
                ->get();

            $responseBody = [];
            foreach ($prices as $price) {
                $roomType = $price->roomType;
                $hotel = $roomType->hotel ?? null;

                if (!$hotel || !$roomType) {
                    continue;
                }

                $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                    $product,
                    $roomType->id,
                    $price->date->format('Y-m-d')
                );

                // 获取库存
                $inventory = \App\Models\Inventory::where('room_type_id', $roomType->id)
                    ->where('date', $price->date)
                    ->first();

                $stock = 0;
                if ($inventory && !$inventory->is_closed) {
                    // 检查销售日期范围
                    $isInSalePeriod = true;
                    if ($product->sale_start_date || $product->sale_end_date) {
                        $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                        $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                        $date = $price->date->format('Y-m-d');
                        
                        if ($saleStartDate && $date < $saleStartDate) {
                            $isInSalePeriod = false;
                        }
                        if ($saleEndDate && $date > $saleEndDate) {
                            $isInSalePeriod = false;
                        }
                    }
                    
                    if ($isInSalePeriod) {
                        $stock = $inventory->available_quantity;
                    }
                }

                // 生成partnerPrimaryKey
                $partnerPrimaryKey = app(\App\Services\OTA\MeituanService::class)->generatePartnerPrimaryKey(
                    $hotel->id,
                    $roomType->id,
                    $price->date->format('Y-m-d')
                );

                $responseBody[] = [
                    'partnerPrimaryKey' => $partnerPrimaryKey,
                    'skuInfo' => [
                        'startTime' => null,
                        'endTime' => null,
                        'levelInfoList' => [
                            [
                                'levelNo' => 1,
                                'levelName' => $hotel->name,
                            ],
                            [
                                'levelNo' => 2,
                                'levelName' => $roomType->name,
                            ],
                        ],
                    ],
                    'priceDate' => $price->date->format('Y-m-d'),
                    'marketPrice' => floatval($priceData['market_price'] ?? $priceData['sale_price']),
                    'mtPrice' => floatval($priceData['sale_price']),
                    'settlementPrice' => floatval($priceData['settlement_price']),
                    'stock' => $stock,
                    'attr' => null,
                ];
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团拉取多层价格日历V2失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }
}


namespace App\Http\Controllers\Webhooks;

use App\Enums\ExceptionOrderType;
use App\Enums\OrderStatus;
use App\Enums\OtaPlatform;
use App\Http\Controllers\Controller;
use App\Http\Client\MeituanClient;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Services\OrderProcessorService;
use App\Services\OrderService;
use App\Services\OrderOperationService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeituanController extends Controller
{
    protected ?MeituanClient $client = null;

    public function __construct(
        protected OrderService $orderService,
        protected OrderProcessorService $orderProcessorService,
        protected InventoryService $inventoryService,
        protected OrderOperationService $orderOperationService
    ) {}

    /**
     * 获取美团客户端
     */
    protected function getClient(): ?MeituanClient
    {
        if ($this->client === null) {
            // 优先使用环境变量配置（如果存在）
            $config = $this->createConfigFromEnv();
            
            // 如果环境变量配置不存在，尝试从数据库读取
            if (!$config) {
                $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
                $config = $platform?->config;
            }

            if (!$config) {
                return null;
            }

            $this->client = new MeituanClient($config);
        }

        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?\App\Models\OtaConfig
    {
        // 检查环境变量是否存在
        if (!env('MEITUAN_PARTNER_ID') || !env('MEITUAN_APP_KEY') || !env('MEITUAN_APP_SECRET')) {
            return null;
        }

        // 创建临时配置对象（不保存到数据库）
        $config = new \App\Models\OtaConfig();
        $config->account = env('MEITUAN_PARTNER_ID'); // PartnerId存储在account字段
        $config->secret_key = env('MEITUAN_APP_KEY'); // AppKey存储在secret_key字段
        $config->aes_key = env('MEITUAN_APP_SECRET'); // AppSecret存储在aes_key字段
        $config->aes_iv = env('MEITUAN_AES_KEY', ''); // AES密钥存储在aes_iv字段
        
        // API URL 配置
        $config->api_url = env('MEITUAN_API_URL', 'https://openapi.meituan.com');
        $config->callback_url = env('MEITUAN_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 美团订单回调接口（统一入口）
     * 根据请求路径或参数判断具体接口类型
     */
    public function handleOrder(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 美团请求格式：请求体是JSON，包含body字段（加密的字符串）
            $requestData = $request->all();
            $encryptedBody = $requestData['body'] ?? '';

            if (empty($encryptedBody)) {
                Log::error('美团订单回调：请求体为空', [
                    'request_data' => $requestData,
                ]);
                return $this->errorResponse(400, '请求体为空');
            }

            // 解密body字段
            try {
                $decryptedBody = $client->decryptBody($encryptedBody);
                $data = json_decode($decryptedBody, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('美团订单回调：JSON解析失败', [
                        'error' => json_last_error_msg(),
                        'decrypted_body' => $decryptedBody,
                    ]);
                    return $this->errorResponse(400, '请求数据格式错误');
                }

                Log::info('美团订单回调解密后数据', [
                    'data' => $data,
                ]);
            } catch (\Exception $e) {
                Log::error('美团订单回调：解密失败', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return $this->errorResponse(400, '请求数据解密失败');
            }

            // 根据请求路径判断接口类型
            $path = $request->path();
            $body = $data['body'] ?? $data;

            // 判断接口类型（优先根据路径判断）
            if (str_contains($path, 'order/create/v2')) {
                return $this->handleOrderCreateV2($data);
            } elseif (str_contains($path, 'order/pay')) {
                return $this->handleOrderPay($data);
            } elseif (str_contains($path, 'order/query')) {
                return $this->handleOrderQuery($data);
            } elseif (str_contains($path, 'order/refund') && !str_contains($path, 'refunded')) {
                return $this->handleOrderRefund($data);
            } elseif (str_contains($path, 'order/refunded')) {
                return $this->handleOrderRefunded($data);
            } elseif (str_contains($path, 'order/close')) {
                return $this->handleOrderClose($data);
            } else {
                // 如果路径无法判断，根据数据内容判断
                if (isset($body['closeType'])) {
                    return $this->handleOrderClose($data);
                } elseif (isset($body['refundSerialNo']) && isset($body['refundTime'])) {
                    return $this->handleOrderRefunded($data);
                } elseif (isset($body['refundSerialNo'])) {
                    return $this->handleOrderRefund($data);
                } elseif (isset($body['orderId']) && isset($body['payTime'])) {
                    return $this->handleOrderPay($data);
                } elseif (isset($body['orderId'])) {
                    // 可能是订单创建或订单查询，需要进一步判断
                    // 订单创建通常有partnerDealId和quantity
                    if (isset($body['partnerDealId']) && isset($body['quantity'])) {
                        return $this->handleOrderCreateV2($data);
                    } else {
                        return $this->handleOrderQuery($data);
                    }
                } else {
                    Log::warning('美团订单回调：未知接口类型', [
                        'path' => $path,
                        'data' => $data,
                    ]);
                    return $this->errorResponse(400, '未知接口类型');
                }
            }
        } catch (\Exception $e) {
            Log::error('美团订单回调异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse(500, '系统处理异常');
        }
    }

    /**
     * 返回错误响应
     */
    protected function errorResponse(int $code, string $message): JsonResponse
    {
        $client = $this->getClient();
        $encryptedBody = '';

        if ($client) {
            try {
                $body = [
                    'code' => $code,
                    'describe' => $message,
                ];
                $encryptedBody = $client->encryptBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (\Exception $e) {
                Log::error('美团响应加密失败', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'code' => $code,
            'describe' => $message,
            'body' => $encryptedBody,
        ]);
    }

    /**
     * 返回成功响应
     */
    protected function successResponse(array $body = []): JsonResponse
    {
        $client = $this->getClient();
        $encryptedBody = '';

        if ($client) {
            try {
                // 美团响应格式：body字段是加密的JSON字符串
                // 如果body为空，返回空字符串
                if (!empty($body)) {
                    $encryptedBody = $client->encryptBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            } catch (\Exception $e) {
                Log::error('美团响应加密失败', [
                    'error' => $e->getMessage(),
                    'body' => $body,
                ]);
            }
        }

        return response()->json([
            'code' => 200,
            'describe' => 'success',
            'body' => $encryptedBody,
        ]);
    }

    /**
     * 处理订单创建V2（对应携程的CreatePreOrder）
     * 需要锁定库存
     */
    protected function handleOrderCreateV2(array $data): JsonResponse
    {
        try {
            DB::beginTransaction();

            $otaPlatform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$otaPlatform) {
                DB::rollBack();
                return $this->errorResponse(500, 'OTA平台配置不存在');
            }

            // 解析请求数据
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $partnerDealId = $body['partnerDealId'] ?? '';
            $quantity = intval($body['quantity'] ?? 1);
            $useDate = $body['useDate'] ?? '';
            $realNameType = intval($body['realNameType'] ?? 0);
            $credentialList = $body['credentialList'] ?? [];
            $contacts = $body['contacts'] ?? [];
            $contactInfo = !empty($contacts) ? $contacts[0] : [];

            // 验证必要参数
            if (empty($orderId)) {
                DB::rollBack();
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            if (empty($partnerDealId)) {
                DB::rollBack();
                return $this->errorResponse(400, '产品编码(partnerDealId)为空');
            }

            if (empty($useDate)) {
                DB::rollBack();
                return $this->errorResponse(400, '使用日期(useDate)为空');
            }

            // 根据产品编码查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                DB::rollBack();
                Log::error('美团订单创建V2：产品不存在', [
                    'partner_deal_id' => $partnerDealId,
                ]);
                return $this->errorResponse(505, '产品不存在');
            }

            // 查找产品关联的酒店和房型（通过价格表）
            $price = $product->prices()->where('date', $useDate)->first();
            if (!$price) {
                DB::rollBack();
                Log::error('美团订单创建V2：指定日期没有价格', [
                    'product_id' => $product->id,
                    'use_date' => $useDate,
                ]);
                return $this->errorResponse(400, '指定日期没有价格');
            }

            $roomType = $price->roomType;
            $hotel = $roomType->hotel ?? null;

            if (!$hotel || !$roomType) {
                DB::rollBack();
                Log::error('美团订单创建V2：产品未关联酒店或房型', [
                    'product_id' => $product->id,
                ]);
                return $this->errorResponse(400, '产品未关联酒店或房型');
            }

            // 检查库存（考虑入住天数）
            $stayDays = $product->stay_days ?: 1;
            $checkInDate = \Carbon\Carbon::parse($useDate);
            
            // 检查连续入住天数的库存是否足够
            $inventoryCheck = $this->checkInventoryForStayDays($roomType->id, $checkInDate, $stayDays, $quantity);
            if (!$inventoryCheck['success']) {
                DB::rollBack();
                return $this->errorResponse(503, $inventoryCheck['message']);
            }

            // 检查是否已存在订单（防止重复）
            $existingOrder = Order::where('ota_order_no', (string)$orderId)
                ->where('ota_platform_id', $otaPlatform->id)
                ->first();

            if ($existingOrder) {
                // 已存在，返回成功（幂等性）
                DB::rollBack();
                Log::info('美团订单创建V2：订单已存在，返回成功', [
                    'order_id' => $orderId,
                    'order_no' => $existingOrder->order_no,
                ]);
                return $this->successResponse([
                    'orderId' => intval($orderId),
                    'partnerOrderId' => $existingOrder->order_no,
                ]);
            }

            // 计算价格
            $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                $product,
                $roomType->id,
                $useDate
            );
            $salePrice = floatval($priceData['sale_price']);
            $settlementPrice = floatval($priceData['settlement_price']);

            // 处理联系人信息
            $contactName = $contactInfo['name'] ?? '';
            $contactPhone = $contactInfo['mobile'] ?? $contactInfo['phone'] ?? '';
            $contactEmail = $contactInfo['email'] ?? '';

            // 处理实名制订单
            $credentialListData = null;
            if ($realNameType === 1 && !empty($credentialList)) {
                $credentialListData = [];
                foreach ($credentialList as $credential) {
                    $credentialListData[] = [
                        'credentialType' => intval($credential['credentialType'] ?? 0),
                        'credentialNo' => $credential['credentialNo'] ?? '',
                        'voucher' => $credential['voucher'] ?? '',
                        'status' => 0, // 0=未使用
                    ];
                }
            }

            // 计算离店日期（根据产品入住天数）
            $stayDays = $product->stay_days ?: 1;
            $checkOutDate = \Carbon\Carbon::parse($useDate)->addDays($stayDays)->format('Y-m-d');
            
            Log::info('美团订单创建V2：根据产品入住天数计算离店日期', [
                'use_date' => $useDate,
                'stay_days' => $stayDays,
                'calculated_check_out_date' => $checkOutDate,
            ]);

            // 创建订单
            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'ota_order_no' => (string)$orderId,
                'ota_platform_id' => $otaPlatform->id,
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'status' => OrderStatus::PAID_PENDING,
                'check_in_date' => $useDate,
                'check_out_date' => $checkOutDate,
                'room_count' => $quantity,
                'guest_count' => $quantity, // 默认等于房间数
                'contact_name' => $contactName,
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail,
                'guest_info' => $credentialList ?? [],
                'real_name_type' => $realNameType,
                'credential_list' => $credentialListData,
                'total_amount' => intval($salePrice * $quantity * 100), // 转换为分
                'settlement_amount' => intval($settlementPrice * $quantity * 100), // 转换为分
                'paid_at' => null, // 订单创建时还未支付
            ]);

            // 锁定库存（订单创建的核心目的就是锁库存）
            $lockResult = $this->lockInventoryForPreOrder($order, $product->stay_days);
            if (!$lockResult['success']) {
                DB::rollBack();
                Log::error('美团订单创建V2：库存锁定失败', [
                    'order_id' => $order->id,
                    'error' => $lockResult['message'],
                ]);
                return $this->errorResponse(503, '库存锁定失败：' . $lockResult['message']);
            }

            DB::commit();

            Log::info('美团订单创建V2成功', [
                'order_id' => $orderId,
                'order_no' => $order->order_no,
                'partner_deal_id' => $partnerDealId,
            ]);

            return $this->successResponse([
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('美团订单创建V2失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常：' . $e->getMessage());
        }
    }

    /**
     * 处理订单出票（对应携程的PayPreOrder）
     */
    protected function handleOrderPay(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $partnerOrderId = $body['partnerOrderId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 如果订单已经是确认状态，直接返回成功（幂等性）
            if ($order->status === OrderStatus::CONFIRMED) {
                return $this->buildOrderPaySuccessResponse($order, $orderId);
            }

            // 检查订单状态（必须是PAID_PENDING）
            if ($order->status !== OrderStatus::PAID_PENDING) {
                return $this->errorResponse(506, '订单状态不正确，当前状态：' . $order->status->label());
            }

            // 更新支付时间
            if (!$order->paid_at) {
                $order->update(['paid_at' => now()]);
            }

            // 检查是否系统直连
            $scenicSpot = $order->hotel->scenicSpot ?? null;
            $isSystemConnected = $scenicSpot && $scenicSpot->is_system_connected;

            // 先响应美团（不等待景区方接口）
            // 系统直连和非系统直连都返回 code=598（出票中）
            $code = 598; // 出票中

            // 异步处理景区方接口调用
            if ($isSystemConnected) {
                // 系统直连：异步调用景区方接口接单（设置 10 秒超时）
                \App\Jobs\ProcessResourceOrderJob::dispatch($order, 'confirm')
                    ->timeout(10);
            } else {
                // 非系统直连：只更新状态为确认中，等待人工接单
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::CONFIRMING,
                    '美团订单出票，等待人工接单'
                );
            }

            // 返回出票中响应
            return $this->successResponse([
                'code' => $code,
                'describe' => '出票中',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单出票处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 构建订单出票成功响应
     */
    protected function buildOrderPaySuccessResponse(Order $order, string $orderId): JsonResponse
    {
        // 构建响应数据
        $responseBody = [
            'code' => 200,
            'describe' => 'success',
            'orderId' => intval($orderId),
            'partnerOrderId' => $order->order_no,
            'voucherType' => 0, // 不需要支持一码一验，统一使用0
            'realNameType' => $order->real_name_type ?? 0,
        ];

        // 如果订单有凭证码，返回凭证码（这里暂时返回空，实际应该从订单中获取）
        $responseBody['vouchers'] = [];
        $responseBody['voucherPics'] = [];
        $responseBody['voucherAdditionalList'] = [];

        // 如果是实名制订单，返回credentialList
        if ($order->real_name_type === 1 && !empty($order->credential_list)) {
            $responseBody['credentialList'] = [];
            foreach ($order->credential_list as $credential) {
                $responseBody['credentialList'][] = [
                    'credentialType' => $credential['credentialType'] ?? 0,
                    'credentialNo' => $credential['credentialNo'] ?? '',
                    'voucher' => $credential['voucher'] ?? '',
                ];
            }
        }

        return $this->successResponse($responseBody);
    }

    /**
     * 处理订单查询（对应携程的QueryOrder）
     */
    protected function handleOrderQuery(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)
                ->with(['product', 'hotel', 'roomType'])
                ->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 映射订单状态到美团状态
            $orderStatus = $this->mapOrderStatusToMeituan($order->status);

            // 构建响应数据
            $responseBody = [
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
                'orderStatus' => $orderStatus,
                'partnerDealId' => $order->product->code ?? '',
                'quantity' => $order->room_count,
                'useDate' => $order->check_in_date->format('Y-m-d'),
                'totalPrice' => floatval($order->total_amount) / 100, // 转换为元
                'settlementPrice' => floatval($order->settlement_amount) / 100, // 转换为元
            ];

            // 如果订单已核销，返回usedQuantity
            if ($order->status === OrderStatus::VERIFIED) {
                $responseBody['usedQuantity'] = $order->room_count;
            }

            // 如果订单已退款，返回refundedQuantity
            if ($order->status === OrderStatus::CANCEL_APPROVED) {
                $responseBody['refundedQuantity'] = $order->room_count;
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团订单查询失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 映射订单状态到美团状态
     */
    protected function mapOrderStatusToMeituan(OrderStatus $status): int
    {
        return match($status) {
            OrderStatus::PAID_PENDING => 2, // 创建订单成功
            OrderStatus::REJECTED => 3, // 创建订单失败
            OrderStatus::CONFIRMED => 4, // 出票成功（用户可以入园）
            OrderStatus::CONFIRMING => 5, // 出票中（向上游二次直连时使用）
            OrderStatus::VERIFIED => 4, // 已使用（通过usedQuantity判断）
            OrderStatus::CANCEL_APPROVED => 4, // 已退款（通过refundedQuantity判断）
            default => 2,
        };
    }

    /**
     * 处理订单退款（对应携程的CancelOrder）
     */
    protected function handleOrderRefund(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $refundQuantity = intval($body['refundQuantity'] ?? 0);

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 检查订单状态（必须是PAID_PENDING或CONFIRMED）
            if (!in_array($order->status, [OrderStatus::PAID_PENDING, OrderStatus::CONFIRMED])) {
                return $this->errorResponse(506, '订单状态不允许退款，当前状态：' . $order->status->label());
            }

            // 如果订单已使用，不允许退款
            if ($order->status === OrderStatus::VERIFIED) {
                return $this->errorResponse(506, '订单已使用，不允许退款');
            }

            // 如果订单已过期，不允许退款
            if ($order->check_in_date < now()->toDateString()) {
                return $this->errorResponse(506, '订单已过期，不允许退款');
            }

            // 验证退款数量
            if ($refundQuantity <= 0 || $refundQuantity > $order->room_count) {
                return $this->errorResponse(400, '退款数量不正确');
            }

            // 检查是否系统直连
            $scenicSpot = $order->hotel->scenicSpot ?? null;
            $isSystemConnected = $scenicSpot && $scenicSpot->is_system_connected;

            // 先响应美团（不等待景区方接口）
            // 系统直连和非系统直连都返回 code=602（审批中）
            $code = 602; // 审批中

            // 异步处理景区方接口调用
            if ($isSystemConnected) {
                // 系统直连：异步调用景区方接口取消订单（设置 10 秒超时）
                \App\Jobs\ProcessResourceCancelOrderJob::dispatch($order, '美团申请退款')
                    ->timeout(10);
            } else {
                // 非系统直连：只更新状态为申请取消中，等待人工处理
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::CANCEL_REQUESTED,
                    '美团申请退款，数量：' . $refundQuantity
                );
            }

            // 返回审批中响应
            return $this->successResponse([
                'code' => $code,
                'describe' => '退款审批中',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单退款处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理已退款消息（新功能）
     */
    protected function handleOrderRefunded(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $refundSerialNo = $body['refundSerialNo'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 幂等性检查：如果订单已存在退款流水号且与请求中的相同，直接返回成功
            if ($order->refund_serial_no && $order->refund_serial_no === $refundSerialNo) {
                return $this->successResponse([
                    'code' => 200,
                    'describe' => 'success',
                    'orderId' => intval($orderId),
                ]);
            }

            // 更新订单状态为CANCEL_APPROVED
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                '美团已退款，退款流水号：' . $refundSerialNo
            );

            // 记录退款流水号
            $order->update(['refund_serial_no' => $refundSerialNo]);

            // 释放库存
            $releaseResult = $this->releaseInventoryForPreOrder($order);
            if (!$releaseResult['success']) {
                Log::warning('美团已退款消息：库存释放失败', [
                    'order_id' => $order->id,
                    'error' => $releaseResult['message'],
                ]);
            }

            return $this->successResponse([
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
            ]);

        } catch (\Exception $e) {
            Log::error('美团已退款消息处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理订单关闭消息（新功能）
     */
    protected function handleOrderClose(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $closeType = intval($body['closeType'] ?? 0);

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 幂等性检查：如果订单状态已经是CANCEL_APPROVED，直接返回成功
            if ($order->status === OrderStatus::CANCEL_APPROVED) {
                return $this->successResponse([
                    'code' => 200,
                    'describe' => 'success',
                    'orderId' => intval($orderId),
                ]);
            }

            // 更新订单状态为CANCEL_APPROVED
            $closeTypeMap = [
                1 => '用户未支付，美团关闭订单',
                2 => '合作方下单接口异常，美团关闭订单',
                3 => '合作方出票接口异常，美团出票失败且已退款',
            ];
            $closeReason = $closeTypeMap[$closeType] ?? '订单关闭';

            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                $closeReason . '，closeType=' . $closeType
            );

            // 记录closeType到订单日志
            \App\Models\OrderLog::create([
                'order_id' => $order->id,
                'from_status' => $order->status->value,
                'to_status' => OrderStatus::CANCEL_APPROVED->value,
                'remark' => $closeReason . '，closeType=' . $closeType,
            ]);

            // 释放库存
            $releaseResult = $this->releaseInventoryForPreOrder($order);
            if (!$releaseResult['success']) {
                Log::warning('美团订单关闭消息：库存释放失败', [
                    'order_id' => $order->id,
                    'error' => $releaseResult['message'],
                ]);
            }

            return $this->successResponse([
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单关闭消息处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 释放库存（预下单）
     */
    protected function releaseInventoryForPreOrder(Order $order): array
    {
        try {
            // 获取入住天数
            $product = $order->product;
            $stayDays = $product->stay_days ?: 1;

            // 获取日期范围
            $dates = $this->inventoryService->getDateRange($order->check_in_date, $stayDays);

            // 使用统一的库存服务释放库存
            $success = $this->inventoryService->releaseInventoryForDates(
                $order->room_type_id,
                $dates,
                $order->room_count
            );

            if ($success) {
                return [
                    'success' => true,
                    'message' => '库存释放成功',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '库存释放失败：系统异常',
                ];
            }
        } catch (\Exception $e) {
            Log::error('预下单库存释放异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '库存释放失败：系统异常',
            ];
        }
    }

    /**
     * 锁定库存（预下单）
     */
    protected function lockInventoryForPreOrder(Order $order, ?int $stayDays = null): array
    {
        try {
            // 获取入住天数
            if ($stayDays === null) {
                $product = $order->product;
                $stayDays = $product->stay_days ?: 1;
            }

            // 获取日期范围
            $dates = $this->inventoryService->getDateRange($order->check_in_date, $stayDays);

            // 使用统一的库存服务锁定库存
            $success = $this->inventoryService->lockInventoryForDates(
                $order->room_type_id,
                $dates,
                $order->room_count
            );

            if ($success) {
                return [
                    'success' => true,
                    'message' => '库存锁定成功',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '库存锁定失败：库存不足或并发冲突',
                ];
            }
        } catch (\Exception $e) {
            Log::error('预下单库存锁定异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '库存锁定失败：系统异常',
            ];
        }
    }

    /**
     * 检查连续入住天数的库存
     */
    protected function checkInventoryForStayDays(int $roomTypeId, \Carbon\Carbon $checkInDate, int $stayDays, int $quantity): array
    {
        $dates = $this->inventoryService->getDateRange($checkInDate->format('Y-m-d'), $stayDays);

        foreach ($dates as $date) {
            $inventory = \App\Models\Inventory::where('room_type_id', $roomTypeId)
                ->where('date', $date)
                ->first();

            if (!$inventory || $inventory->is_closed || $inventory->available_quantity < $quantity) {
                return [
                    'success' => false,
                    'message' => "日期 {$date} 库存不足或已关闭",
                ];
            }
        }

        return ['success' => true];
    }

    /**
     * 生成订单号
     */
    protected function generateOrderNo(): string
    {
        return 'ORD' . date('YmdHis') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * 处理拉取价格日历（美团主动拉取）
     */
    public function handleProductPriceCalendar(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 解密请求体
            $encryptedBody = $request->input('body', '');
            if (empty($encryptedBody)) {
                return $this->errorResponse(400, '请求体为空');
            }

            $decryptedBody = $client->decryptBody($encryptedBody);
            $data = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse(400, '请求数据格式错误');
            }

            $body = $data['body'] ?? $data;
            $partnerDealId = $body['partnerDealId'] ?? '';
            $startTime = $body['startTime'] ?? '';
            $endTime = $body['endTime'] ?? '';

            if (empty($partnerDealId) || empty($startTime) || empty($endTime)) {
                return $this->errorResponse(400, '参数不完整');
            }

            // 查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                return $this->errorResponse(505, '产品不存在');
            }

            // 获取价格数据
            $prices = $product->prices()
                ->whereBetween('date', [$startTime, $endTime])
                ->with('roomType')
                ->get();

            $responseBody = [];
            foreach ($prices as $price) {
                $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                    $product,
                    $price->room_type_id,
                    $price->date->format('Y-m-d')
                );

                // 获取库存
                $inventory = \App\Models\Inventory::where('room_type_id', $price->room_type_id)
                    ->where('date', $price->date)
                    ->first();

                $stock = 0;
                if ($inventory && !$inventory->is_closed) {
                    // 检查销售日期范围
                    $isInSalePeriod = true;
                    if ($product->sale_start_date || $product->sale_end_date) {
                        $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                        $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                        $date = $price->date->format('Y-m-d');
                        
                        if ($saleStartDate && $date < $saleStartDate) {
                            $isInSalePeriod = false;
                        }
                        if ($saleEndDate && $date > $saleEndDate) {
                            $isInSalePeriod = false;
                        }
                    }
                    
                    if ($isInSalePeriod) {
                        $stock = $inventory->available_quantity;
                    }
                }

                $responseBody[] = [
                    'partnerDealId' => $partnerDealId,
                    'date' => $price->date->format('Y-m-d'),
                    'mtPrice' => floatval($priceData['sale_price']),
                    'marketPrice' => floatval($priceData['market_price'] ?? $priceData['sale_price']),
                    'settlementPrice' => floatval($priceData['settlement_price']),
                    'stock' => $stock,
                ];
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团拉取价格日历失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理拉取多层价格日历V2（美团主动拉取）
     */
    public function handleProductLevelPriceCalendarV2(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 解密请求体
            $encryptedBody = $request->input('body', '');
            if (empty($encryptedBody)) {
                return $this->errorResponse(400, '请求体为空');
            }

            $decryptedBody = $client->decryptBody($encryptedBody);
            $data = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse(400, '请求数据格式错误');
            }

            $body = $data['body'] ?? $data;
            $partnerDealId = $body['partnerDealId'] ?? '';
            $startTime = $body['startTime'] ?? '';
            $endTime = $body['endTime'] ?? '';
            $asyncType = intval($body['asyncType'] ?? 0); // 0=同步，1=异步

            if (empty($partnerDealId) || empty($startTime) || empty($endTime)) {
                return $this->errorResponse(400, '参数不完整');
            }

            // 查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                return $this->errorResponse(505, '产品不存在');
            }

            // 如果异步拉取，返回code=999，然后通过"多层价格日历变化通知V2"推送
            if ($asyncType === 1) {
                // TODO: 触发异步推送任务
                return $this->successResponse([
                    'code' => 999,
                    'describe' => '异步拉取，将通过通知接口推送',
                ]);
            }

            // 同步拉取：直接返回价格日历数据
            // 获取产品的所有"产品-酒店-房型"组合
            $prices = $product->prices()
                ->whereBetween('date', [$startTime, $endTime])
                ->with(['roomType.hotel'])
                ->get();

            $responseBody = [];
            foreach ($prices as $price) {
                $roomType = $price->roomType;
                $hotel = $roomType->hotel ?? null;

                if (!$hotel || !$roomType) {
                    continue;
                }

                $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                    $product,
                    $roomType->id,
                    $price->date->format('Y-m-d')
                );

                // 获取库存
                $inventory = \App\Models\Inventory::where('room_type_id', $roomType->id)
                    ->where('date', $price->date)
                    ->first();

                $stock = 0;
                if ($inventory && !$inventory->is_closed) {
                    // 检查销售日期范围
                    $isInSalePeriod = true;
                    if ($product->sale_start_date || $product->sale_end_date) {
                        $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                        $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                        $date = $price->date->format('Y-m-d');
                        
                        if ($saleStartDate && $date < $saleStartDate) {
                            $isInSalePeriod = false;
                        }
                        if ($saleEndDate && $date > $saleEndDate) {
                            $isInSalePeriod = false;
                        }
                    }
                    
                    if ($isInSalePeriod) {
                        $stock = $inventory->available_quantity;
                    }
                }

                // 生成partnerPrimaryKey
                $partnerPrimaryKey = app(\App\Services\OTA\MeituanService::class)->generatePartnerPrimaryKey(
                    $hotel->id,
                    $roomType->id,
                    $price->date->format('Y-m-d')
                );

                $responseBody[] = [
                    'partnerPrimaryKey' => $partnerPrimaryKey,
                    'skuInfo' => [
                        'startTime' => null,
                        'endTime' => null,
                        'levelInfoList' => [
                            [
                                'levelNo' => 1,
                                'levelName' => $hotel->name,
                            ],
                            [
                                'levelNo' => 2,
                                'levelName' => $roomType->name,
                            ],
                        ],
                    ],
                    'priceDate' => $price->date->format('Y-m-d'),
                    'marketPrice' => floatval($priceData['market_price'] ?? $priceData['sale_price']),
                    'mtPrice' => floatval($priceData['sale_price']),
                    'settlementPrice' => floatval($priceData['settlement_price']),
                    'stock' => $stock,
                    'attr' => null,
                ];
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团拉取多层价格日历V2失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }
}


namespace App\Http\Controllers\Webhooks;

use App\Enums\ExceptionOrderType;
use App\Enums\OrderStatus;
use App\Enums\OtaPlatform;
use App\Http\Controllers\Controller;
use App\Http\Client\MeituanClient;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Services\OrderProcessorService;
use App\Services\OrderService;
use App\Services\OrderOperationService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeituanController extends Controller
{
    protected ?MeituanClient $client = null;

    public function __construct(
        protected OrderService $orderService,
        protected OrderProcessorService $orderProcessorService,
        protected InventoryService $inventoryService,
        protected OrderOperationService $orderOperationService
    ) {}

    /**
     * 获取美团客户端
     */
    protected function getClient(): ?MeituanClient
    {
        if ($this->client === null) {
            // 优先使用环境变量配置（如果存在）
            $config = $this->createConfigFromEnv();
            
            // 如果环境变量配置不存在，尝试从数据库读取
            if (!$config) {
                $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
                $config = $platform?->config;
            }

            if (!$config) {
                return null;
            }

            $this->client = new MeituanClient($config);
        }

        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?\App\Models\OtaConfig
    {
        // 检查环境变量是否存在
        if (!env('MEITUAN_PARTNER_ID') || !env('MEITUAN_APP_KEY') || !env('MEITUAN_APP_SECRET')) {
            return null;
        }

        // 创建临时配置对象（不保存到数据库）
        $config = new \App\Models\OtaConfig();
        $config->account = env('MEITUAN_PARTNER_ID'); // PartnerId存储在account字段
        $config->secret_key = env('MEITUAN_APP_KEY'); // AppKey存储在secret_key字段
        $config->aes_key = env('MEITUAN_APP_SECRET'); // AppSecret存储在aes_key字段
        $config->aes_iv = env('MEITUAN_AES_KEY', ''); // AES密钥存储在aes_iv字段
        
        // API URL 配置
        $config->api_url = env('MEITUAN_API_URL', 'https://openapi.meituan.com');
        $config->callback_url = env('MEITUAN_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 美团订单回调接口（统一入口）
     * 根据请求路径或参数判断具体接口类型
     */
    public function handleOrder(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 美团请求格式：请求体是JSON，包含body字段（加密的字符串）
            $requestData = $request->all();
            $encryptedBody = $requestData['body'] ?? '';

            if (empty($encryptedBody)) {
                Log::error('美团订单回调：请求体为空', [
                    'request_data' => $requestData,
                ]);
                return $this->errorResponse(400, '请求体为空');
            }

            // 解密body字段
            try {
                $decryptedBody = $client->decryptBody($encryptedBody);
                $data = json_decode($decryptedBody, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('美团订单回调：JSON解析失败', [
                        'error' => json_last_error_msg(),
                        'decrypted_body' => $decryptedBody,
                    ]);
                    return $this->errorResponse(400, '请求数据格式错误');
                }

                Log::info('美团订单回调解密后数据', [
                    'data' => $data,
                ]);
            } catch (\Exception $e) {
                Log::error('美团订单回调：解密失败', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return $this->errorResponse(400, '请求数据解密失败');
            }

            // 根据请求路径判断接口类型
            $path = $request->path();
            $body = $data['body'] ?? $data;

            // 判断接口类型（优先根据路径判断）
            if (str_contains($path, 'order/create/v2')) {
                return $this->handleOrderCreateV2($data);
            } elseif (str_contains($path, 'order/pay')) {
                return $this->handleOrderPay($data);
            } elseif (str_contains($path, 'order/query')) {
                return $this->handleOrderQuery($data);
            } elseif (str_contains($path, 'order/refund') && !str_contains($path, 'refunded')) {
                return $this->handleOrderRefund($data);
            } elseif (str_contains($path, 'order/refunded')) {
                return $this->handleOrderRefunded($data);
            } elseif (str_contains($path, 'order/close')) {
                return $this->handleOrderClose($data);
            } else {
                // 如果路径无法判断，根据数据内容判断
                if (isset($body['closeType'])) {
                    return $this->handleOrderClose($data);
                } elseif (isset($body['refundSerialNo']) && isset($body['refundTime'])) {
                    return $this->handleOrderRefunded($data);
                } elseif (isset($body['refundSerialNo'])) {
                    return $this->handleOrderRefund($data);
                } elseif (isset($body['orderId']) && isset($body['payTime'])) {
                    return $this->handleOrderPay($data);
                } elseif (isset($body['orderId'])) {
                    // 可能是订单创建或订单查询，需要进一步判断
                    // 订单创建通常有partnerDealId和quantity
                    if (isset($body['partnerDealId']) && isset($body['quantity'])) {
                        return $this->handleOrderCreateV2($data);
                    } else {
                        return $this->handleOrderQuery($data);
                    }
                } else {
                    Log::warning('美团订单回调：未知接口类型', [
                        'path' => $path,
                        'data' => $data,
                    ]);
                    return $this->errorResponse(400, '未知接口类型');
                }
            }
        } catch (\Exception $e) {
            Log::error('美团订单回调异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse(500, '系统处理异常');
        }
    }

    /**
     * 返回错误响应
     */
    protected function errorResponse(int $code, string $message): JsonResponse
    {
        $client = $this->getClient();
        $encryptedBody = '';

        if ($client) {
            try {
                $body = [
                    'code' => $code,
                    'describe' => $message,
                ];
                $encryptedBody = $client->encryptBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (\Exception $e) {
                Log::error('美团响应加密失败', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'code' => $code,
            'describe' => $message,
            'body' => $encryptedBody,
        ]);
    }

    /**
     * 返回成功响应
     */
    protected function successResponse(array $body = []): JsonResponse
    {
        $client = $this->getClient();
        $encryptedBody = '';

        if ($client) {
            try {
                // 美团响应格式：body字段是加密的JSON字符串
                // 如果body为空，返回空字符串
                if (!empty($body)) {
                    $encryptedBody = $client->encryptBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            } catch (\Exception $e) {
                Log::error('美团响应加密失败', [
                    'error' => $e->getMessage(),
                    'body' => $body,
                ]);
            }
        }

        return response()->json([
            'code' => 200,
            'describe' => 'success',
            'body' => $encryptedBody,
        ]);
    }

    /**
     * 处理订单创建V2（对应携程的CreatePreOrder）
     * 需要锁定库存
     */
    protected function handleOrderCreateV2(array $data): JsonResponse
    {
        try {
            DB::beginTransaction();

            $otaPlatform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$otaPlatform) {
                DB::rollBack();
                return $this->errorResponse(500, 'OTA平台配置不存在');
            }

            // 解析请求数据
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $partnerDealId = $body['partnerDealId'] ?? '';
            $quantity = intval($body['quantity'] ?? 1);
            $useDate = $body['useDate'] ?? '';
            $realNameType = intval($body['realNameType'] ?? 0);
            $credentialList = $body['credentialList'] ?? [];
            $contacts = $body['contacts'] ?? [];
            $contactInfo = !empty($contacts) ? $contacts[0] : [];

            // 验证必要参数
            if (empty($orderId)) {
                DB::rollBack();
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            if (empty($partnerDealId)) {
                DB::rollBack();
                return $this->errorResponse(400, '产品编码(partnerDealId)为空');
            }

            if (empty($useDate)) {
                DB::rollBack();
                return $this->errorResponse(400, '使用日期(useDate)为空');
            }

            // 根据产品编码查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                DB::rollBack();
                Log::error('美团订单创建V2：产品不存在', [
                    'partner_deal_id' => $partnerDealId,
                ]);
                return $this->errorResponse(505, '产品不存在');
            }

            // 查找产品关联的酒店和房型（通过价格表）
            $price = $product->prices()->where('date', $useDate)->first();
            if (!$price) {
                DB::rollBack();
                Log::error('美团订单创建V2：指定日期没有价格', [
                    'product_id' => $product->id,
                    'use_date' => $useDate,
                ]);
                return $this->errorResponse(400, '指定日期没有价格');
            }

            $roomType = $price->roomType;
            $hotel = $roomType->hotel ?? null;

            if (!$hotel || !$roomType) {
                DB::rollBack();
                Log::error('美团订单创建V2：产品未关联酒店或房型', [
                    'product_id' => $product->id,
                ]);
                return $this->errorResponse(400, '产品未关联酒店或房型');
            }

            // 检查库存（考虑入住天数）
            $stayDays = $product->stay_days ?: 1;
            $checkInDate = \Carbon\Carbon::parse($useDate);
            
            // 检查连续入住天数的库存是否足够
            $inventoryCheck = $this->checkInventoryForStayDays($roomType->id, $checkInDate, $stayDays, $quantity);
            if (!$inventoryCheck['success']) {
                DB::rollBack();
                return $this->errorResponse(503, $inventoryCheck['message']);
            }

            // 检查是否已存在订单（防止重复）
            $existingOrder = Order::where('ota_order_no', (string)$orderId)
                ->where('ota_platform_id', $otaPlatform->id)
                ->first();

            if ($existingOrder) {
                // 已存在，返回成功（幂等性）
                DB::rollBack();
                Log::info('美团订单创建V2：订单已存在，返回成功', [
                    'order_id' => $orderId,
                    'order_no' => $existingOrder->order_no,
                ]);
                return $this->successResponse([
                    'orderId' => intval($orderId),
                    'partnerOrderId' => $existingOrder->order_no,
                ]);
            }

            // 计算价格
            $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                $product,
                $roomType->id,
                $useDate
            );
            $salePrice = floatval($priceData['sale_price']);
            $settlementPrice = floatval($priceData['settlement_price']);

            // 处理联系人信息
            $contactName = $contactInfo['name'] ?? '';
            $contactPhone = $contactInfo['mobile'] ?? $contactInfo['phone'] ?? '';
            $contactEmail = $contactInfo['email'] ?? '';

            // 处理实名制订单
            $credentialListData = null;
            if ($realNameType === 1 && !empty($credentialList)) {
                $credentialListData = [];
                foreach ($credentialList as $credential) {
                    $credentialListData[] = [
                        'credentialType' => intval($credential['credentialType'] ?? 0),
                        'credentialNo' => $credential['credentialNo'] ?? '',
                        'voucher' => $credential['voucher'] ?? '',
                        'status' => 0, // 0=未使用
                    ];
                }
            }

            // 计算离店日期（根据产品入住天数）
            $stayDays = $product->stay_days ?: 1;
            $checkOutDate = \Carbon\Carbon::parse($useDate)->addDays($stayDays)->format('Y-m-d');
            
            Log::info('美团订单创建V2：根据产品入住天数计算离店日期', [
                'use_date' => $useDate,
                'stay_days' => $stayDays,
                'calculated_check_out_date' => $checkOutDate,
            ]);

            // 创建订单
            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'ota_order_no' => (string)$orderId,
                'ota_platform_id' => $otaPlatform->id,
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'status' => OrderStatus::PAID_PENDING,
                'check_in_date' => $useDate,
                'check_out_date' => $checkOutDate,
                'room_count' => $quantity,
                'guest_count' => $quantity, // 默认等于房间数
                'contact_name' => $contactName,
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail,
                'guest_info' => $credentialList ?? [],
                'real_name_type' => $realNameType,
                'credential_list' => $credentialListData,
                'total_amount' => intval($salePrice * $quantity * 100), // 转换为分
                'settlement_amount' => intval($settlementPrice * $quantity * 100), // 转换为分
                'paid_at' => null, // 订单创建时还未支付
            ]);

            // 锁定库存（订单创建的核心目的就是锁库存）
            $lockResult = $this->lockInventoryForPreOrder($order, $product->stay_days);
            if (!$lockResult['success']) {
                DB::rollBack();
                Log::error('美团订单创建V2：库存锁定失败', [
                    'order_id' => $order->id,
                    'error' => $lockResult['message'],
                ]);
                return $this->errorResponse(503, '库存锁定失败：' . $lockResult['message']);
            }

            DB::commit();

            Log::info('美团订单创建V2成功', [
                'order_id' => $orderId,
                'order_no' => $order->order_no,
                'partner_deal_id' => $partnerDealId,
            ]);

            return $this->successResponse([
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('美团订单创建V2失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常：' . $e->getMessage());
        }
    }

    /**
     * 处理订单出票（对应携程的PayPreOrder）
     */
    protected function handleOrderPay(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $partnerOrderId = $body['partnerOrderId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 如果订单已经是确认状态，直接返回成功（幂等性）
            if ($order->status === OrderStatus::CONFIRMED) {
                return $this->buildOrderPaySuccessResponse($order, $orderId);
            }

            // 检查订单状态（必须是PAID_PENDING）
            if ($order->status !== OrderStatus::PAID_PENDING) {
                return $this->errorResponse(506, '订单状态不正确，当前状态：' . $order->status->label());
            }

            // 更新支付时间
            if (!$order->paid_at) {
                $order->update(['paid_at' => now()]);
            }

            // 检查是否系统直连
            $scenicSpot = $order->hotel->scenicSpot ?? null;
            $isSystemConnected = $scenicSpot && $scenicSpot->is_system_connected;

            // 先响应美团（不等待景区方接口）
            // 系统直连和非系统直连都返回 code=598（出票中）
            $code = 598; // 出票中

            // 异步处理景区方接口调用
            if ($isSystemConnected) {
                // 系统直连：异步调用景区方接口接单（设置 10 秒超时）
                \App\Jobs\ProcessResourceOrderJob::dispatch($order, 'confirm')
                    ->timeout(10);
            } else {
                // 非系统直连：只更新状态为确认中，等待人工接单
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::CONFIRMING,
                    '美团订单出票，等待人工接单'
                );
            }

            // 返回出票中响应
            return $this->successResponse([
                'code' => $code,
                'describe' => '出票中',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单出票处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 构建订单出票成功响应
     */
    protected function buildOrderPaySuccessResponse(Order $order, string $orderId): JsonResponse
    {
        // 构建响应数据
        $responseBody = [
            'code' => 200,
            'describe' => 'success',
            'orderId' => intval($orderId),
            'partnerOrderId' => $order->order_no,
            'voucherType' => 0, // 不需要支持一码一验，统一使用0
            'realNameType' => $order->real_name_type ?? 0,
        ];

        // 如果订单有凭证码，返回凭证码（这里暂时返回空，实际应该从订单中获取）
        $responseBody['vouchers'] = [];
        $responseBody['voucherPics'] = [];
        $responseBody['voucherAdditionalList'] = [];

        // 如果是实名制订单，返回credentialList
        if ($order->real_name_type === 1 && !empty($order->credential_list)) {
            $responseBody['credentialList'] = [];
            foreach ($order->credential_list as $credential) {
                $responseBody['credentialList'][] = [
                    'credentialType' => $credential['credentialType'] ?? 0,
                    'credentialNo' => $credential['credentialNo'] ?? '',
                    'voucher' => $credential['voucher'] ?? '',
                ];
            }
        }

        return $this->successResponse($responseBody);
    }

    /**
     * 处理订单查询（对应携程的QueryOrder）
     */
    protected function handleOrderQuery(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)
                ->with(['product', 'hotel', 'roomType'])
                ->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 映射订单状态到美团状态
            $orderStatus = $this->mapOrderStatusToMeituan($order->status);

            // 构建响应数据
            $responseBody = [
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
                'orderStatus' => $orderStatus,
                'partnerDealId' => $order->product->code ?? '',
                'quantity' => $order->room_count,
                'useDate' => $order->check_in_date->format('Y-m-d'),
                'totalPrice' => floatval($order->total_amount) / 100, // 转换为元
                'settlementPrice' => floatval($order->settlement_amount) / 100, // 转换为元
            ];

            // 如果订单已核销，返回usedQuantity
            if ($order->status === OrderStatus::VERIFIED) {
                $responseBody['usedQuantity'] = $order->room_count;
            }

            // 如果订单已退款，返回refundedQuantity
            if ($order->status === OrderStatus::CANCEL_APPROVED) {
                $responseBody['refundedQuantity'] = $order->room_count;
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团订单查询失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 映射订单状态到美团状态
     */
    protected function mapOrderStatusToMeituan(OrderStatus $status): int
    {
        return match($status) {
            OrderStatus::PAID_PENDING => 2, // 创建订单成功
            OrderStatus::REJECTED => 3, // 创建订单失败
            OrderStatus::CONFIRMED => 4, // 出票成功（用户可以入园）
            OrderStatus::CONFIRMING => 5, // 出票中（向上游二次直连时使用）
            OrderStatus::VERIFIED => 4, // 已使用（通过usedQuantity判断）
            OrderStatus::CANCEL_APPROVED => 4, // 已退款（通过refundedQuantity判断）
            default => 2,
        };
    }

    /**
     * 处理订单退款（对应携程的CancelOrder）
     */
    protected function handleOrderRefund(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $refundQuantity = intval($body['refundQuantity'] ?? 0);

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 检查订单状态（必须是PAID_PENDING或CONFIRMED）
            if (!in_array($order->status, [OrderStatus::PAID_PENDING, OrderStatus::CONFIRMED])) {
                return $this->errorResponse(506, '订单状态不允许退款，当前状态：' . $order->status->label());
            }

            // 如果订单已使用，不允许退款
            if ($order->status === OrderStatus::VERIFIED) {
                return $this->errorResponse(506, '订单已使用，不允许退款');
            }

            // 如果订单已过期，不允许退款
            if ($order->check_in_date < now()->toDateString()) {
                return $this->errorResponse(506, '订单已过期，不允许退款');
            }

            // 验证退款数量
            if ($refundQuantity <= 0 || $refundQuantity > $order->room_count) {
                return $this->errorResponse(400, '退款数量不正确');
            }

            // 检查是否系统直连
            $scenicSpot = $order->hotel->scenicSpot ?? null;
            $isSystemConnected = $scenicSpot && $scenicSpot->is_system_connected;

            // 先响应美团（不等待景区方接口）
            // 系统直连和非系统直连都返回 code=602（审批中）
            $code = 602; // 审批中

            // 异步处理景区方接口调用
            if ($isSystemConnected) {
                // 系统直连：异步调用景区方接口取消订单（设置 10 秒超时）
                \App\Jobs\ProcessResourceCancelOrderJob::dispatch($order, '美团申请退款')
                    ->timeout(10);
            } else {
                // 非系统直连：只更新状态为申请取消中，等待人工处理
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::CANCEL_REQUESTED,
                    '美团申请退款，数量：' . $refundQuantity
                );
            }

            // 返回审批中响应
            return $this->successResponse([
                'code' => $code,
                'describe' => '退款审批中',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单退款处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理已退款消息（新功能）
     */
    protected function handleOrderRefunded(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $refundSerialNo = $body['refundSerialNo'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 幂等性检查：如果订单已存在退款流水号且与请求中的相同，直接返回成功
            if ($order->refund_serial_no && $order->refund_serial_no === $refundSerialNo) {
                return $this->successResponse([
                    'code' => 200,
                    'describe' => 'success',
                    'orderId' => intval($orderId),
                ]);
            }

            // 更新订单状态为CANCEL_APPROVED
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                '美团已退款，退款流水号：' . $refundSerialNo
            );

            // 记录退款流水号
            $order->update(['refund_serial_no' => $refundSerialNo]);

            // 释放库存
            $releaseResult = $this->releaseInventoryForPreOrder($order);
            if (!$releaseResult['success']) {
                Log::warning('美团已退款消息：库存释放失败', [
                    'order_id' => $order->id,
                    'error' => $releaseResult['message'],
                ]);
            }

            return $this->successResponse([
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
            ]);

        } catch (\Exception $e) {
            Log::error('美团已退款消息处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理订单关闭消息（新功能）
     */
    protected function handleOrderClose(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $closeType = intval($body['closeType'] ?? 0);

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 幂等性检查：如果订单状态已经是CANCEL_APPROVED，直接返回成功
            if ($order->status === OrderStatus::CANCEL_APPROVED) {
                return $this->successResponse([
                    'code' => 200,
                    'describe' => 'success',
                    'orderId' => intval($orderId),
                ]);
            }

            // 更新订单状态为CANCEL_APPROVED
            $closeTypeMap = [
                1 => '用户未支付，美团关闭订单',
                2 => '合作方下单接口异常，美团关闭订单',
                3 => '合作方出票接口异常，美团出票失败且已退款',
            ];
            $closeReason = $closeTypeMap[$closeType] ?? '订单关闭';

            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                $closeReason . '，closeType=' . $closeType
            );

            // 记录closeType到订单日志
            \App\Models\OrderLog::create([
                'order_id' => $order->id,
                'from_status' => $order->status->value,
                'to_status' => OrderStatus::CANCEL_APPROVED->value,
                'remark' => $closeReason . '，closeType=' . $closeType,
            ]);

            // 释放库存
            $releaseResult = $this->releaseInventoryForPreOrder($order);
            if (!$releaseResult['success']) {
                Log::warning('美团订单关闭消息：库存释放失败', [
                    'order_id' => $order->id,
                    'error' => $releaseResult['message'],
                ]);
            }

            return $this->successResponse([
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单关闭消息处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 释放库存（预下单）
     */
    protected function releaseInventoryForPreOrder(Order $order): array
    {
        try {
            // 获取入住天数
            $product = $order->product;
            $stayDays = $product->stay_days ?: 1;

            // 获取日期范围
            $dates = $this->inventoryService->getDateRange($order->check_in_date, $stayDays);

            // 使用统一的库存服务释放库存
            $success = $this->inventoryService->releaseInventoryForDates(
                $order->room_type_id,
                $dates,
                $order->room_count
            );

            if ($success) {
                return [
                    'success' => true,
                    'message' => '库存释放成功',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '库存释放失败：系统异常',
                ];
            }
        } catch (\Exception $e) {
            Log::error('预下单库存释放异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '库存释放失败：系统异常',
            ];
        }
    }

    /**
     * 锁定库存（预下单）
     */
    protected function lockInventoryForPreOrder(Order $order, ?int $stayDays = null): array
    {
        try {
            // 获取入住天数
            if ($stayDays === null) {
                $product = $order->product;
                $stayDays = $product->stay_days ?: 1;
            }

            // 获取日期范围
            $dates = $this->inventoryService->getDateRange($order->check_in_date, $stayDays);

            // 使用统一的库存服务锁定库存
            $success = $this->inventoryService->lockInventoryForDates(
                $order->room_type_id,
                $dates,
                $order->room_count
            );

            if ($success) {
                return [
                    'success' => true,
                    'message' => '库存锁定成功',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '库存锁定失败：库存不足或并发冲突',
                ];
            }
        } catch (\Exception $e) {
            Log::error('预下单库存锁定异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '库存锁定失败：系统异常',
            ];
        }
    }

    /**
     * 检查连续入住天数的库存
     */
    protected function checkInventoryForStayDays(int $roomTypeId, \Carbon\Carbon $checkInDate, int $stayDays, int $quantity): array
    {
        $dates = $this->inventoryService->getDateRange($checkInDate->format('Y-m-d'), $stayDays);

        foreach ($dates as $date) {
            $inventory = \App\Models\Inventory::where('room_type_id', $roomTypeId)
                ->where('date', $date)
                ->first();

            if (!$inventory || $inventory->is_closed || $inventory->available_quantity < $quantity) {
                return [
                    'success' => false,
                    'message' => "日期 {$date} 库存不足或已关闭",
                ];
            }
        }

        return ['success' => true];
    }

    /**
     * 生成订单号
     */
    protected function generateOrderNo(): string
    {
        return 'ORD' . date('YmdHis') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * 处理拉取价格日历（美团主动拉取）
     */
    public function handleProductPriceCalendar(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 解密请求体
            $encryptedBody = $request->input('body', '');
            if (empty($encryptedBody)) {
                return $this->errorResponse(400, '请求体为空');
            }

            $decryptedBody = $client->decryptBody($encryptedBody);
            $data = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse(400, '请求数据格式错误');
            }

            $body = $data['body'] ?? $data;
            $partnerDealId = $body['partnerDealId'] ?? '';
            $startTime = $body['startTime'] ?? '';
            $endTime = $body['endTime'] ?? '';

            if (empty($partnerDealId) || empty($startTime) || empty($endTime)) {
                return $this->errorResponse(400, '参数不完整');
            }

            // 查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                return $this->errorResponse(505, '产品不存在');
            }

            // 获取价格数据
            $prices = $product->prices()
                ->whereBetween('date', [$startTime, $endTime])
                ->with('roomType')
                ->get();

            $responseBody = [];
            foreach ($prices as $price) {
                $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                    $product,
                    $price->room_type_id,
                    $price->date->format('Y-m-d')
                );

                // 获取库存
                $inventory = \App\Models\Inventory::where('room_type_id', $price->room_type_id)
                    ->where('date', $price->date)
                    ->first();

                $stock = 0;
                if ($inventory && !$inventory->is_closed) {
                    // 检查销售日期范围
                    $isInSalePeriod = true;
                    if ($product->sale_start_date || $product->sale_end_date) {
                        $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                        $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                        $date = $price->date->format('Y-m-d');
                        
                        if ($saleStartDate && $date < $saleStartDate) {
                            $isInSalePeriod = false;
                        }
                        if ($saleEndDate && $date > $saleEndDate) {
                            $isInSalePeriod = false;
                        }
                    }
                    
                    if ($isInSalePeriod) {
                        $stock = $inventory->available_quantity;
                    }
                }

                $responseBody[] = [
                    'partnerDealId' => $partnerDealId,
                    'date' => $price->date->format('Y-m-d'),
                    'mtPrice' => floatval($priceData['sale_price']),
                    'marketPrice' => floatval($priceData['market_price'] ?? $priceData['sale_price']),
                    'settlementPrice' => floatval($priceData['settlement_price']),
                    'stock' => $stock,
                ];
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团拉取价格日历失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理拉取多层价格日历V2（美团主动拉取）
     */
    public function handleProductLevelPriceCalendarV2(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 解密请求体
            $encryptedBody = $request->input('body', '');
            if (empty($encryptedBody)) {
                return $this->errorResponse(400, '请求体为空');
            }

            $decryptedBody = $client->decryptBody($encryptedBody);
            $data = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse(400, '请求数据格式错误');
            }

            $body = $data['body'] ?? $data;
            $partnerDealId = $body['partnerDealId'] ?? '';
            $startTime = $body['startTime'] ?? '';
            $endTime = $body['endTime'] ?? '';
            $asyncType = intval($body['asyncType'] ?? 0); // 0=同步，1=异步

            if (empty($partnerDealId) || empty($startTime) || empty($endTime)) {
                return $this->errorResponse(400, '参数不完整');
            }

            // 查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                return $this->errorResponse(505, '产品不存在');
            }

            // 如果异步拉取，返回code=999，然后通过"多层价格日历变化通知V2"推送
            if ($asyncType === 1) {
                // TODO: 触发异步推送任务
                return $this->successResponse([
                    'code' => 999,
                    'describe' => '异步拉取，将通过通知接口推送',
                ]);
            }

            // 同步拉取：直接返回价格日历数据
            // 获取产品的所有"产品-酒店-房型"组合
            $prices = $product->prices()
                ->whereBetween('date', [$startTime, $endTime])
                ->with(['roomType.hotel'])
                ->get();

            $responseBody = [];
            foreach ($prices as $price) {
                $roomType = $price->roomType;
                $hotel = $roomType->hotel ?? null;

                if (!$hotel || !$roomType) {
                    continue;
                }

                $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                    $product,
                    $roomType->id,
                    $price->date->format('Y-m-d')
                );

                // 获取库存
                $inventory = \App\Models\Inventory::where('room_type_id', $roomType->id)
                    ->where('date', $price->date)
                    ->first();

                $stock = 0;
                if ($inventory && !$inventory->is_closed) {
                    // 检查销售日期范围
                    $isInSalePeriod = true;
                    if ($product->sale_start_date || $product->sale_end_date) {
                        $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                        $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                        $date = $price->date->format('Y-m-d');
                        
                        if ($saleStartDate && $date < $saleStartDate) {
                            $isInSalePeriod = false;
                        }
                        if ($saleEndDate && $date > $saleEndDate) {
                            $isInSalePeriod = false;
                        }
                    }
                    
                    if ($isInSalePeriod) {
                        $stock = $inventory->available_quantity;
                    }
                }

                // 生成partnerPrimaryKey
                $partnerPrimaryKey = app(\App\Services\OTA\MeituanService::class)->generatePartnerPrimaryKey(
                    $hotel->id,
                    $roomType->id,
                    $price->date->format('Y-m-d')
                );

                $responseBody[] = [
                    'partnerPrimaryKey' => $partnerPrimaryKey,
                    'skuInfo' => [
                        'startTime' => null,
                        'endTime' => null,
                        'levelInfoList' => [
                            [
                                'levelNo' => 1,
                                'levelName' => $hotel->name,
                            ],
                            [
                                'levelNo' => 2,
                                'levelName' => $roomType->name,
                            ],
                        ],
                    ],
                    'priceDate' => $price->date->format('Y-m-d'),
                    'marketPrice' => floatval($priceData['market_price'] ?? $priceData['sale_price']),
                    'mtPrice' => floatval($priceData['sale_price']),
                    'settlementPrice' => floatval($priceData['settlement_price']),
                    'stock' => $stock,
                    'attr' => null,
                ];
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团拉取多层价格日历V2失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }
}


namespace App\Http\Controllers\Webhooks;

use App\Enums\ExceptionOrderType;
use App\Enums\OrderStatus;
use App\Enums\OtaPlatform;
use App\Http\Controllers\Controller;
use App\Http\Client\MeituanClient;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Services\OrderProcessorService;
use App\Services\OrderService;
use App\Services\OrderOperationService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeituanController extends Controller
{
    protected ?MeituanClient $client = null;

    public function __construct(
        protected OrderService $orderService,
        protected OrderProcessorService $orderProcessorService,
        protected InventoryService $inventoryService,
        protected OrderOperationService $orderOperationService
    ) {}

    /**
     * 获取美团客户端
     */
    protected function getClient(): ?MeituanClient
    {
        if ($this->client === null) {
            // 优先使用环境变量配置（如果存在）
            $config = $this->createConfigFromEnv();
            
            // 如果环境变量配置不存在，尝试从数据库读取
            if (!$config) {
                $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
                $config = $platform?->config;
            }

            if (!$config) {
                return null;
            }

            $this->client = new MeituanClient($config);
        }

        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?\App\Models\OtaConfig
    {
        // 检查环境变量是否存在
        if (!env('MEITUAN_PARTNER_ID') || !env('MEITUAN_APP_KEY') || !env('MEITUAN_APP_SECRET')) {
            return null;
        }

        // 创建临时配置对象（不保存到数据库）
        $config = new \App\Models\OtaConfig();
        $config->account = env('MEITUAN_PARTNER_ID'); // PartnerId存储在account字段
        $config->secret_key = env('MEITUAN_APP_KEY'); // AppKey存储在secret_key字段
        $config->aes_key = env('MEITUAN_APP_SECRET'); // AppSecret存储在aes_key字段
        $config->aes_iv = env('MEITUAN_AES_KEY', ''); // AES密钥存储在aes_iv字段
        
        // API URL 配置
        $config->api_url = env('MEITUAN_API_URL', 'https://openapi.meituan.com');
        $config->callback_url = env('MEITUAN_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 美团订单回调接口（统一入口）
     * 根据请求路径或参数判断具体接口类型
     */
    public function handleOrder(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 美团请求格式：请求体是JSON，包含body字段（加密的字符串）
            $requestData = $request->all();
            $encryptedBody = $requestData['body'] ?? '';

            if (empty($encryptedBody)) {
                Log::error('美团订单回调：请求体为空', [
                    'request_data' => $requestData,
                ]);
                return $this->errorResponse(400, '请求体为空');
            }

            // 解密body字段
            try {
                $decryptedBody = $client->decryptBody($encryptedBody);
                $data = json_decode($decryptedBody, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('美团订单回调：JSON解析失败', [
                        'error' => json_last_error_msg(),
                        'decrypted_body' => $decryptedBody,
                    ]);
                    return $this->errorResponse(400, '请求数据格式错误');
                }

                Log::info('美团订单回调解密后数据', [
                    'data' => $data,
                ]);
            } catch (\Exception $e) {
                Log::error('美团订单回调：解密失败', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return $this->errorResponse(400, '请求数据解密失败');
            }

            // 根据请求路径判断接口类型
            $path = $request->path();
            $body = $data['body'] ?? $data;

            // 判断接口类型（优先根据路径判断）
            if (str_contains($path, 'order/create/v2')) {
                return $this->handleOrderCreateV2($data);
            } elseif (str_contains($path, 'order/pay')) {
                return $this->handleOrderPay($data);
            } elseif (str_contains($path, 'order/query')) {
                return $this->handleOrderQuery($data);
            } elseif (str_contains($path, 'order/refund') && !str_contains($path, 'refunded')) {
                return $this->handleOrderRefund($data);
            } elseif (str_contains($path, 'order/refunded')) {
                return $this->handleOrderRefunded($data);
            } elseif (str_contains($path, 'order/close')) {
                return $this->handleOrderClose($data);
            } else {
                // 如果路径无法判断，根据数据内容判断
                if (isset($body['closeType'])) {
                    return $this->handleOrderClose($data);
                } elseif (isset($body['refundSerialNo']) && isset($body['refundTime'])) {
                    return $this->handleOrderRefunded($data);
                } elseif (isset($body['refundSerialNo'])) {
                    return $this->handleOrderRefund($data);
                } elseif (isset($body['orderId']) && isset($body['payTime'])) {
                    return $this->handleOrderPay($data);
                } elseif (isset($body['orderId'])) {
                    // 可能是订单创建或订单查询，需要进一步判断
                    // 订单创建通常有partnerDealId和quantity
                    if (isset($body['partnerDealId']) && isset($body['quantity'])) {
                        return $this->handleOrderCreateV2($data);
                    } else {
                        return $this->handleOrderQuery($data);
                    }
                } else {
                    Log::warning('美团订单回调：未知接口类型', [
                        'path' => $path,
                        'data' => $data,
                    ]);
                    return $this->errorResponse(400, '未知接口类型');
                }
            }
        } catch (\Exception $e) {
            Log::error('美团订单回调异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse(500, '系统处理异常');
        }
    }

    /**
     * 返回错误响应
     */
    protected function errorResponse(int $code, string $message): JsonResponse
    {
        $client = $this->getClient();
        $encryptedBody = '';

        if ($client) {
            try {
                $body = [
                    'code' => $code,
                    'describe' => $message,
                ];
                $encryptedBody = $client->encryptBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (\Exception $e) {
                Log::error('美团响应加密失败', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'code' => $code,
            'describe' => $message,
            'body' => $encryptedBody,
        ]);
    }

    /**
     * 返回成功响应
     */
    protected function successResponse(array $body = []): JsonResponse
    {
        $client = $this->getClient();
        $encryptedBody = '';

        if ($client) {
            try {
                // 美团响应格式：body字段是加密的JSON字符串
                // 如果body为空，返回空字符串
                if (!empty($body)) {
                    $encryptedBody = $client->encryptBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            } catch (\Exception $e) {
                Log::error('美团响应加密失败', [
                    'error' => $e->getMessage(),
                    'body' => $body,
                ]);
            }
        }

        return response()->json([
            'code' => 200,
            'describe' => 'success',
            'body' => $encryptedBody,
        ]);
    }

    /**
     * 处理订单创建V2（对应携程的CreatePreOrder）
     * 需要锁定库存
     */
    protected function handleOrderCreateV2(array $data): JsonResponse
    {
        try {
            DB::beginTransaction();

            $otaPlatform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$otaPlatform) {
                DB::rollBack();
                return $this->errorResponse(500, 'OTA平台配置不存在');
            }

            // 解析请求数据
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $partnerDealId = $body['partnerDealId'] ?? '';
            $quantity = intval($body['quantity'] ?? 1);
            $useDate = $body['useDate'] ?? '';
            $realNameType = intval($body['realNameType'] ?? 0);
            $credentialList = $body['credentialList'] ?? [];
            $contacts = $body['contacts'] ?? [];
            $contactInfo = !empty($contacts) ? $contacts[0] : [];

            // 验证必要参数
            if (empty($orderId)) {
                DB::rollBack();
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            if (empty($partnerDealId)) {
                DB::rollBack();
                return $this->errorResponse(400, '产品编码(partnerDealId)为空');
            }

            if (empty($useDate)) {
                DB::rollBack();
                return $this->errorResponse(400, '使用日期(useDate)为空');
            }

            // 根据产品编码查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                DB::rollBack();
                Log::error('美团订单创建V2：产品不存在', [
                    'partner_deal_id' => $partnerDealId,
                ]);
                return $this->errorResponse(505, '产品不存在');
            }

            // 查找产品关联的酒店和房型（通过价格表）
            $price = $product->prices()->where('date', $useDate)->first();
            if (!$price) {
                DB::rollBack();
                Log::error('美团订单创建V2：指定日期没有价格', [
                    'product_id' => $product->id,
                    'use_date' => $useDate,
                ]);
                return $this->errorResponse(400, '指定日期没有价格');
            }

            $roomType = $price->roomType;
            $hotel = $roomType->hotel ?? null;

            if (!$hotel || !$roomType) {
                DB::rollBack();
                Log::error('美团订单创建V2：产品未关联酒店或房型', [
                    'product_id' => $product->id,
                ]);
                return $this->errorResponse(400, '产品未关联酒店或房型');
            }

            // 检查库存（考虑入住天数）
            $stayDays = $product->stay_days ?: 1;
            $checkInDate = \Carbon\Carbon::parse($useDate);
            
            // 检查连续入住天数的库存是否足够
            $inventoryCheck = $this->checkInventoryForStayDays($roomType->id, $checkInDate, $stayDays, $quantity);
            if (!$inventoryCheck['success']) {
                DB::rollBack();
                return $this->errorResponse(503, $inventoryCheck['message']);
            }

            // 检查是否已存在订单（防止重复）
            $existingOrder = Order::where('ota_order_no', (string)$orderId)
                ->where('ota_platform_id', $otaPlatform->id)
                ->first();

            if ($existingOrder) {
                // 已存在，返回成功（幂等性）
                DB::rollBack();
                Log::info('美团订单创建V2：订单已存在，返回成功', [
                    'order_id' => $orderId,
                    'order_no' => $existingOrder->order_no,
                ]);
                return $this->successResponse([
                    'orderId' => intval($orderId),
                    'partnerOrderId' => $existingOrder->order_no,
                ]);
            }

            // 计算价格
            $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                $product,
                $roomType->id,
                $useDate
            );
            $salePrice = floatval($priceData['sale_price']);
            $settlementPrice = floatval($priceData['settlement_price']);

            // 处理联系人信息
            $contactName = $contactInfo['name'] ?? '';
            $contactPhone = $contactInfo['mobile'] ?? $contactInfo['phone'] ?? '';
            $contactEmail = $contactInfo['email'] ?? '';

            // 处理实名制订单
            $credentialListData = null;
            if ($realNameType === 1 && !empty($credentialList)) {
                $credentialListData = [];
                foreach ($credentialList as $credential) {
                    $credentialListData[] = [
                        'credentialType' => intval($credential['credentialType'] ?? 0),
                        'credentialNo' => $credential['credentialNo'] ?? '',
                        'voucher' => $credential['voucher'] ?? '',
                        'status' => 0, // 0=未使用
                    ];
                }
            }

            // 计算离店日期（根据产品入住天数）
            $stayDays = $product->stay_days ?: 1;
            $checkOutDate = \Carbon\Carbon::parse($useDate)->addDays($stayDays)->format('Y-m-d');
            
            Log::info('美团订单创建V2：根据产品入住天数计算离店日期', [
                'use_date' => $useDate,
                'stay_days' => $stayDays,
                'calculated_check_out_date' => $checkOutDate,
            ]);

            // 创建订单
            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'ota_order_no' => (string)$orderId,
                'ota_platform_id' => $otaPlatform->id,
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'status' => OrderStatus::PAID_PENDING,
                'check_in_date' => $useDate,
                'check_out_date' => $checkOutDate,
                'room_count' => $quantity,
                'guest_count' => $quantity, // 默认等于房间数
                'contact_name' => $contactName,
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail,
                'guest_info' => $credentialList ?? [],
                'real_name_type' => $realNameType,
                'credential_list' => $credentialListData,
                'total_amount' => intval($salePrice * $quantity * 100), // 转换为分
                'settlement_amount' => intval($settlementPrice * $quantity * 100), // 转换为分
                'paid_at' => null, // 订单创建时还未支付
            ]);

            // 锁定库存（订单创建的核心目的就是锁库存）
            $lockResult = $this->lockInventoryForPreOrder($order, $product->stay_days);
            if (!$lockResult['success']) {
                DB::rollBack();
                Log::error('美团订单创建V2：库存锁定失败', [
                    'order_id' => $order->id,
                    'error' => $lockResult['message'],
                ]);
                return $this->errorResponse(503, '库存锁定失败：' . $lockResult['message']);
            }

            DB::commit();

            Log::info('美团订单创建V2成功', [
                'order_id' => $orderId,
                'order_no' => $order->order_no,
                'partner_deal_id' => $partnerDealId,
            ]);

            return $this->successResponse([
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('美团订单创建V2失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常：' . $e->getMessage());
        }
    }

    /**
     * 处理订单出票（对应携程的PayPreOrder）
     */
    protected function handleOrderPay(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $partnerOrderId = $body['partnerOrderId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 如果订单已经是确认状态，直接返回成功（幂等性）
            if ($order->status === OrderStatus::CONFIRMED) {
                return $this->buildOrderPaySuccessResponse($order, $orderId);
            }

            // 检查订单状态（必须是PAID_PENDING）
            if ($order->status !== OrderStatus::PAID_PENDING) {
                return $this->errorResponse(506, '订单状态不正确，当前状态：' . $order->status->label());
            }

            // 更新支付时间
            if (!$order->paid_at) {
                $order->update(['paid_at' => now()]);
            }

            // 检查是否系统直连
            $scenicSpot = $order->hotel->scenicSpot ?? null;
            $isSystemConnected = $scenicSpot && $scenicSpot->is_system_connected;

            // 先响应美团（不等待景区方接口）
            // 系统直连和非系统直连都返回 code=598（出票中）
            $code = 598; // 出票中

            // 异步处理景区方接口调用
            if ($isSystemConnected) {
                // 系统直连：异步调用景区方接口接单（设置 10 秒超时）
                \App\Jobs\ProcessResourceOrderJob::dispatch($order, 'confirm')
                    ->timeout(10);
            } else {
                // 非系统直连：只更新状态为确认中，等待人工接单
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::CONFIRMING,
                    '美团订单出票，等待人工接单'
                );
            }

            // 返回出票中响应
            return $this->successResponse([
                'code' => $code,
                'describe' => '出票中',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单出票处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 构建订单出票成功响应
     */
    protected function buildOrderPaySuccessResponse(Order $order, string $orderId): JsonResponse
    {
        // 构建响应数据
        $responseBody = [
            'code' => 200,
            'describe' => 'success',
            'orderId' => intval($orderId),
            'partnerOrderId' => $order->order_no,
            'voucherType' => 0, // 不需要支持一码一验，统一使用0
            'realNameType' => $order->real_name_type ?? 0,
        ];

        // 如果订单有凭证码，返回凭证码（这里暂时返回空，实际应该从订单中获取）
        $responseBody['vouchers'] = [];
        $responseBody['voucherPics'] = [];
        $responseBody['voucherAdditionalList'] = [];

        // 如果是实名制订单，返回credentialList
        if ($order->real_name_type === 1 && !empty($order->credential_list)) {
            $responseBody['credentialList'] = [];
            foreach ($order->credential_list as $credential) {
                $responseBody['credentialList'][] = [
                    'credentialType' => $credential['credentialType'] ?? 0,
                    'credentialNo' => $credential['credentialNo'] ?? '',
                    'voucher' => $credential['voucher'] ?? '',
                ];
            }
        }

        return $this->successResponse($responseBody);
    }

    /**
     * 处理订单查询（对应携程的QueryOrder）
     */
    protected function handleOrderQuery(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)
                ->with(['product', 'hotel', 'roomType'])
                ->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 映射订单状态到美团状态
            $orderStatus = $this->mapOrderStatusToMeituan($order->status);

            // 构建响应数据
            $responseBody = [
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
                'orderStatus' => $orderStatus,
                'partnerDealId' => $order->product->code ?? '',
                'quantity' => $order->room_count,
                'useDate' => $order->check_in_date->format('Y-m-d'),
                'totalPrice' => floatval($order->total_amount) / 100, // 转换为元
                'settlementPrice' => floatval($order->settlement_amount) / 100, // 转换为元
            ];

            // 如果订单已核销，返回usedQuantity
            if ($order->status === OrderStatus::VERIFIED) {
                $responseBody['usedQuantity'] = $order->room_count;
            }

            // 如果订单已退款，返回refundedQuantity
            if ($order->status === OrderStatus::CANCEL_APPROVED) {
                $responseBody['refundedQuantity'] = $order->room_count;
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团订单查询失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 映射订单状态到美团状态
     */
    protected function mapOrderStatusToMeituan(OrderStatus $status): int
    {
        return match($status) {
            OrderStatus::PAID_PENDING => 2, // 创建订单成功
            OrderStatus::REJECTED => 3, // 创建订单失败
            OrderStatus::CONFIRMED => 4, // 出票成功（用户可以入园）
            OrderStatus::CONFIRMING => 5, // 出票中（向上游二次直连时使用）
            OrderStatus::VERIFIED => 4, // 已使用（通过usedQuantity判断）
            OrderStatus::CANCEL_APPROVED => 4, // 已退款（通过refundedQuantity判断）
            default => 2,
        };
    }

    /**
     * 处理订单退款（对应携程的CancelOrder）
     */
    protected function handleOrderRefund(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $refundQuantity = intval($body['refundQuantity'] ?? 0);

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 检查订单状态（必须是PAID_PENDING或CONFIRMED）
            if (!in_array($order->status, [OrderStatus::PAID_PENDING, OrderStatus::CONFIRMED])) {
                return $this->errorResponse(506, '订单状态不允许退款，当前状态：' . $order->status->label());
            }

            // 如果订单已使用，不允许退款
            if ($order->status === OrderStatus::VERIFIED) {
                return $this->errorResponse(506, '订单已使用，不允许退款');
            }

            // 如果订单已过期，不允许退款
            if ($order->check_in_date < now()->toDateString()) {
                return $this->errorResponse(506, '订单已过期，不允许退款');
            }

            // 验证退款数量
            if ($refundQuantity <= 0 || $refundQuantity > $order->room_count) {
                return $this->errorResponse(400, '退款数量不正确');
            }

            // 检查是否系统直连
            $scenicSpot = $order->hotel->scenicSpot ?? null;
            $isSystemConnected = $scenicSpot && $scenicSpot->is_system_connected;

            // 先响应美团（不等待景区方接口）
            // 系统直连和非系统直连都返回 code=602（审批中）
            $code = 602; // 审批中

            // 异步处理景区方接口调用
            if ($isSystemConnected) {
                // 系统直连：异步调用景区方接口取消订单（设置 10 秒超时）
                \App\Jobs\ProcessResourceCancelOrderJob::dispatch($order, '美团申请退款')
                    ->timeout(10);
            } else {
                // 非系统直连：只更新状态为申请取消中，等待人工处理
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::CANCEL_REQUESTED,
                    '美团申请退款，数量：' . $refundQuantity
                );
            }

            // 返回审批中响应
            return $this->successResponse([
                'code' => $code,
                'describe' => '退款审批中',
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单退款处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理已退款消息（新功能）
     */
    protected function handleOrderRefunded(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $refundSerialNo = $body['refundSerialNo'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 幂等性检查：如果订单已存在退款流水号且与请求中的相同，直接返回成功
            if ($order->refund_serial_no && $order->refund_serial_no === $refundSerialNo) {
                return $this->successResponse([
                    'code' => 200,
                    'describe' => 'success',
                    'orderId' => intval($orderId),
                ]);
            }

            // 更新订单状态为CANCEL_APPROVED
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                '美团已退款，退款流水号：' . $refundSerialNo
            );

            // 记录退款流水号
            $order->update(['refund_serial_no' => $refundSerialNo]);

            // 释放库存
            $releaseResult = $this->releaseInventoryForPreOrder($order);
            if (!$releaseResult['success']) {
                Log::warning('美团已退款消息：库存释放失败', [
                    'order_id' => $order->id,
                    'error' => $releaseResult['message'],
                ]);
            }

            return $this->successResponse([
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
            ]);

        } catch (\Exception $e) {
            Log::error('美团已退款消息处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理订单关闭消息（新功能）
     */
    protected function handleOrderClose(array $data): JsonResponse
    {
        try {
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $closeType = intval($body['closeType'] ?? 0);

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空');
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在');
            }

            // 幂等性检查：如果订单状态已经是CANCEL_APPROVED，直接返回成功
            if ($order->status === OrderStatus::CANCEL_APPROVED) {
                return $this->successResponse([
                    'code' => 200,
                    'describe' => 'success',
                    'orderId' => intval($orderId),
                ]);
            }

            // 更新订单状态为CANCEL_APPROVED
            $closeTypeMap = [
                1 => '用户未支付，美团关闭订单',
                2 => '合作方下单接口异常，美团关闭订单',
                3 => '合作方出票接口异常，美团出票失败且已退款',
            ];
            $closeReason = $closeTypeMap[$closeType] ?? '订单关闭';

            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                $closeReason . '，closeType=' . $closeType
            );

            // 记录closeType到订单日志
            \App\Models\OrderLog::create([
                'order_id' => $order->id,
                'from_status' => $order->status->value,
                'to_status' => OrderStatus::CANCEL_APPROVED->value,
                'remark' => $closeReason . '，closeType=' . $closeType,
            ]);

            // 释放库存
            $releaseResult = $this->releaseInventoryForPreOrder($order);
            if (!$releaseResult['success']) {
                Log::warning('美团订单关闭消息：库存释放失败', [
                    'order_id' => $order->id,
                    'error' => $releaseResult['message'],
                ]);
            }

            return $this->successResponse([
                'code' => 200,
                'describe' => 'success',
                'orderId' => intval($orderId),
            ]);

        } catch (\Exception $e) {
            Log::error('美团订单关闭消息处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 释放库存（预下单）
     */
    protected function releaseInventoryForPreOrder(Order $order): array
    {
        try {
            // 获取入住天数
            $product = $order->product;
            $stayDays = $product->stay_days ?: 1;

            // 获取日期范围
            $dates = $this->inventoryService->getDateRange($order->check_in_date, $stayDays);

            // 使用统一的库存服务释放库存
            $success = $this->inventoryService->releaseInventoryForDates(
                $order->room_type_id,
                $dates,
                $order->room_count
            );

            if ($success) {
                return [
                    'success' => true,
                    'message' => '库存释放成功',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '库存释放失败：系统异常',
                ];
            }
        } catch (\Exception $e) {
            Log::error('预下单库存释放异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '库存释放失败：系统异常',
            ];
        }
    }

    /**
     * 锁定库存（预下单）
     */
    protected function lockInventoryForPreOrder(Order $order, ?int $stayDays = null): array
    {
        try {
            // 获取入住天数
            if ($stayDays === null) {
                $product = $order->product;
                $stayDays = $product->stay_days ?: 1;
            }

            // 获取日期范围
            $dates = $this->inventoryService->getDateRange($order->check_in_date, $stayDays);

            // 使用统一的库存服务锁定库存
            $success = $this->inventoryService->lockInventoryForDates(
                $order->room_type_id,
                $dates,
                $order->room_count
            );

            if ($success) {
                return [
                    'success' => true,
                    'message' => '库存锁定成功',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '库存锁定失败：库存不足或并发冲突',
                ];
            }
        } catch (\Exception $e) {
            Log::error('预下单库存锁定异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '库存锁定失败：系统异常',
            ];
        }
    }

    /**
     * 检查连续入住天数的库存
     */
    protected function checkInventoryForStayDays(int $roomTypeId, \Carbon\Carbon $checkInDate, int $stayDays, int $quantity): array
    {
        $dates = $this->inventoryService->getDateRange($checkInDate->format('Y-m-d'), $stayDays);

        foreach ($dates as $date) {
            $inventory = \App\Models\Inventory::where('room_type_id', $roomTypeId)
                ->where('date', $date)
                ->first();

            if (!$inventory || $inventory->is_closed || $inventory->available_quantity < $quantity) {
                return [
                    'success' => false,
                    'message' => "日期 {$date} 库存不足或已关闭",
                ];
            }
        }

        return ['success' => true];
    }

    /**
     * 生成订单号
     */
    protected function generateOrderNo(): string
    {
        return 'ORD' . date('YmdHis') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * 处理拉取价格日历（美团主动拉取）
     */
    public function handleProductPriceCalendar(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 解密请求体
            $encryptedBody = $request->input('body', '');
            if (empty($encryptedBody)) {
                return $this->errorResponse(400, '请求体为空');
            }

            $decryptedBody = $client->decryptBody($encryptedBody);
            $data = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse(400, '请求数据格式错误');
            }

            $body = $data['body'] ?? $data;
            $partnerDealId = $body['partnerDealId'] ?? '';
            $startTime = $body['startTime'] ?? '';
            $endTime = $body['endTime'] ?? '';

            if (empty($partnerDealId) || empty($startTime) || empty($endTime)) {
                return $this->errorResponse(400, '参数不完整');
            }

            // 查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                return $this->errorResponse(505, '产品不存在');
            }

            // 获取价格数据
            $prices = $product->prices()
                ->whereBetween('date', [$startTime, $endTime])
                ->with('roomType')
                ->get();

            $responseBody = [];
            foreach ($prices as $price) {
                $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                    $product,
                    $price->room_type_id,
                    $price->date->format('Y-m-d')
                );

                // 获取库存
                $inventory = \App\Models\Inventory::where('room_type_id', $price->room_type_id)
                    ->where('date', $price->date)
                    ->first();

                $stock = 0;
                if ($inventory && !$inventory->is_closed) {
                    // 检查销售日期范围
                    $isInSalePeriod = true;
                    if ($product->sale_start_date || $product->sale_end_date) {
                        $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                        $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                        $date = $price->date->format('Y-m-d');
                        
                        if ($saleStartDate && $date < $saleStartDate) {
                            $isInSalePeriod = false;
                        }
                        if ($saleEndDate && $date > $saleEndDate) {
                            $isInSalePeriod = false;
                        }
                    }
                    
                    if ($isInSalePeriod) {
                        $stock = $inventory->available_quantity;
                    }
                }

                $responseBody[] = [
                    'partnerDealId' => $partnerDealId,
                    'date' => $price->date->format('Y-m-d'),
                    'mtPrice' => floatval($priceData['sale_price']),
                    'marketPrice' => floatval($priceData['market_price'] ?? $priceData['sale_price']),
                    'settlementPrice' => floatval($priceData['settlement_price']),
                    'stock' => $stock,
                ];
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团拉取价格日历失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }

    /**
     * 处理拉取多层价格日历V2（美团主动拉取）
     */
    public function handleProductLevelPriceCalendarV2(Request $request): JsonResponse
    {
        try {
            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在');
            }

            // 解密请求体
            $encryptedBody = $request->input('body', '');
            if (empty($encryptedBody)) {
                return $this->errorResponse(400, '请求体为空');
            }

            $decryptedBody = $client->decryptBody($encryptedBody);
            $data = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse(400, '请求数据格式错误');
            }

            $body = $data['body'] ?? $data;
            $partnerDealId = $body['partnerDealId'] ?? '';
            $startTime = $body['startTime'] ?? '';
            $endTime = $body['endTime'] ?? '';
            $asyncType = intval($body['asyncType'] ?? 0); // 0=同步，1=异步

            if (empty($partnerDealId) || empty($startTime) || empty($endTime)) {
                return $this->errorResponse(400, '参数不完整');
            }

            // 查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                return $this->errorResponse(505, '产品不存在');
            }

            // 如果异步拉取，返回code=999，然后通过"多层价格日历变化通知V2"推送
            if ($asyncType === 1) {
                // TODO: 触发异步推送任务
                return $this->successResponse([
                    'code' => 999,
                    'describe' => '异步拉取，将通过通知接口推送',
                ]);
            }

            // 同步拉取：直接返回价格日历数据
            // 获取产品的所有"产品-酒店-房型"组合
            $prices = $product->prices()
                ->whereBetween('date', [$startTime, $endTime])
                ->with(['roomType.hotel'])
                ->get();

            $responseBody = [];
            foreach ($prices as $price) {
                $roomType = $price->roomType;
                $hotel = $roomType->hotel ?? null;

                if (!$hotel || !$roomType) {
                    continue;
                }

                $priceData = app(\App\Services\ProductService::class)->calculatePrice(
                    $product,
                    $roomType->id,
                    $price->date->format('Y-m-d')
                );

                // 获取库存
                $inventory = \App\Models\Inventory::where('room_type_id', $roomType->id)
                    ->where('date', $price->date)
                    ->first();

                $stock = 0;
                if ($inventory && !$inventory->is_closed) {
                    // 检查销售日期范围
                    $isInSalePeriod = true;
                    if ($product->sale_start_date || $product->sale_end_date) {
                        $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                        $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                        $date = $price->date->format('Y-m-d');
                        
                        if ($saleStartDate && $date < $saleStartDate) {
                            $isInSalePeriod = false;
                        }
                        if ($saleEndDate && $date > $saleEndDate) {
                            $isInSalePeriod = false;
                        }
                    }
                    
                    if ($isInSalePeriod) {
                        $stock = $inventory->available_quantity;
                    }
                }

                // 生成partnerPrimaryKey
                $partnerPrimaryKey = app(\App\Services\OTA\MeituanService::class)->generatePartnerPrimaryKey(
                    $hotel->id,
                    $roomType->id,
                    $price->date->format('Y-m-d')
                );

                $responseBody[] = [
                    'partnerPrimaryKey' => $partnerPrimaryKey,
                    'skuInfo' => [
                        'startTime' => null,
                        'endTime' => null,
                        'levelInfoList' => [
                            [
                                'levelNo' => 1,
                                'levelName' => $hotel->name,
                            ],
                            [
                                'levelNo' => 2,
                                'levelName' => $roomType->name,
                            ],
                        ],
                    ],
                    'priceDate' => $price->date->format('Y-m-d'),
                    'marketPrice' => floatval($priceData['market_price'] ?? $priceData['sale_price']),
                    'mtPrice' => floatval($priceData['sale_price']),
                    'settlementPrice' => floatval($priceData['settlement_price']),
                    'stock' => $stock,
                    'attr' => null,
                ];
            }

            return $this->successResponse($responseBody);
        } catch (\Exception $e) {
            Log::error('美团拉取多层价格日历V2失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(599, '系统处理异常');
        }
    }
}
