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
use App\Services\Resource\ResourceServiceFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        // 根据美团文档，正确的API地址是 https://connectivity-adapter.meituan.com
        $config->api_url = env('MEITUAN_API_URL', 'https://connectivity-adapter.meituan.com');
        $config->callback_url = env('MEITUAN_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 美团订单回调接口（统一入口）
     * 根据请求路径或参数判断具体接口类型
     */
    public function handleOrder(Request $request): Response
    {
        try {
            // 记录美团原始请求，便于排查请求格式和加密问题
            Log::info('美团订单回调原始请求', [
                'headers' => [
                    'PartnerId' => $request->header('PartnerId'),
                    'Authorization' => $request->header('Authorization'),
                    'Date' => $request->header('Date'),
                    'AppKey' => $request->header('AppKey'),
                    'X-Encryption-Status' => $request->header('X-Encryption-Status'),
                ],
                'request_all' => $request->all(),
                'raw_body' => $request->getContent(),
                'path' => $request->path(),
            ]);

            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在', null);
            }

            // 获取partnerId（用于错误响应）
            $partnerId = $client->getPartnerId();

            // 获取原始请求体（美团发送的是加密的Base64字符串）
            $rawBody = $request->getContent();
            if (empty($rawBody)) {
                Log::warning('美团订单回调：原始请求体为空');
                return $this->errorResponse(400, '请求体为空', $partnerId);
            }

            // 解密请求体
            try {
                $decryptedBody = $client->decryptBody($rawBody);
                Log::info('美团订单回调解密后数据', [
                    'decrypted_body' => $decryptedBody,
                ]);
            } catch (\Exception $e) {
                Log::error('美团订单回调：解密失败', [
                    'error' => $e->getMessage(),
                    'raw_body_preview' => substr($rawBody, 0, 100),
                    'trace' => $e->getTraceAsString(),
                ]);
                return $this->errorResponse(400, '请求数据解密失败', $partnerId);
            }

            $data = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('美团订单回调：JSON解析失败', [
                    'error' => json_last_error_msg(),
                    'decrypted_body' => $decryptedBody,
                ]);
                return $this->errorResponse(400, '请求数据格式错误', $partnerId);
            }

            // 根据请求路径判断接口类型
            $path = $request->path();
            $body = $data['body'] ?? $data;

            // 判断接口类型（优先根据路径判断）
            if (str_contains($path, 'order/create/v2')) {
                return $this->handleOrderCreateV2($data);
            } elseif (str_contains($path, 'order/pay')) {
                return $this->handleOrderPay($data, $request);
            } elseif (str_contains($path, 'order/query')) {
                return $this->handleOrderQuery($data);
            } elseif (str_contains($path, 'order/refund') && !str_contains($path, 'refunded')) {
                return $this->handleOrderRefund($data, $request);
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
                    return $this->handleOrderRefund($data, $request);
                } elseif (isset($body['orderId']) && isset($body['payTime'])) {
                    return $this->handleOrderPay($data, $request);
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
                    return $this->errorResponse(400, '未知接口类型', $partnerId);
                }
            }
        } catch (\Exception $e) {
            Log::error('美团订单回调异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $partnerId = $this->getClient() ? $this->getClient()->getPartnerId() : null;
            return $this->errorResponse(500, '系统处理异常', $partnerId);
        }
    }

    /**
     * 返回错误响应（支持加密/不加密）
     * 根据接口类型决定是否加密：
     * - 订单创建V2：全局加密
     * - 其他接口：不加密
     */
    protected function errorResponse(int $code, string $message, ?int $partnerId = null, bool $encrypt = false): Response
    {
        $client = $this->getClient();
        
        if (!$client) {
            // 如果没有客户端，返回未加密的响应（仅用于调试）
            Log::warning('美团响应：客户端不存在，返回未加密响应', [
                'code' => $code,
                'message' => $message,
            ]);
            $responseData = [
                'code' => $code,
                'describe' => $message,
            ];
            return response(json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200)
                ->header('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            // 构建完整的响应数据
            $responseData = [
                'code' => $code,
                'describe' => $message,
            ];
            
            // 如果有partnerId，添加到响应中
            if ($partnerId !== null) {
                $responseData['partnerId'] = $partnerId;
            }

            // 根据 encrypt 参数决定是否加密
            if ($encrypt) {
                // 将整个响应体JSON进行AES加密
                $jsonString = json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $encryptedBody = $client->encryptBody($jsonString);

                // 返回加密后的Base64字符串（作为响应体）
                // 注意：响应体是字符串，不是JSON对象
                return response($encryptedBody, 200)
                    ->header('Content-Type', 'application/json; charset=utf-8');
            } else {
                // 不加密，直接返回JSON
                return response(json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200)
                    ->header('Content-Type', 'application/json; charset=utf-8');
            }
        } catch (\Exception $e) {
            Log::error('美团响应加密失败', [
                'error' => $e->getMessage(),
                'code' => $code,
                'message' => $message,
                'trace' => $e->getTraceAsString(),
            ]);
            
            // 加密失败时，返回未加密的响应（仅用于调试）
            $responseData = [
                'code' => $code,
                'describe' => $message,
                'error' => '响应加密失败',
            ];
            return response(json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200)
                ->header('Content-Type', 'application/json; charset=utf-8');
        }
    }

    /**
     * 返回成功响应（支持加密/不加密）
     * 根据接口类型决定是否加密：
     * - 订单创建V2：全局加密
     * - 其他接口：不加密
     * 
     * @param array $body 响应体数据（不应该包含code和describe字段）
     * @param int|null $partnerId 合作方ID（可选，如果提供会添加到响应中）
     * @param string|null $partnerDealId 产品ID（可选，如果提供会添加到响应中）
     * @param int $code 响应码（默认200，出票中时传入598）
     * @param string $describe 响应描述（默认"success"，出票中时传入"出票中"）
     * @param bool $encrypt 是否加密（默认false，订单创建V2需要传入true）
     * @return \Illuminate\Http\Response
     */
    protected function successResponse(
        array $body = [], 
        ?int $partnerId = null, 
        ?string $partnerDealId = null,
        int $code = 200,
        string $describe = 'success',
        bool $encrypt = false
    ): Response {
        $client = $this->getClient();
        
        if (!$client) {
            // 如果没有客户端，返回未加密的响应（仅用于调试）
            Log::warning('美团响应：客户端不存在，返回未加密响应', [
                'body' => $body,
                'code' => $code,
                'describe' => $describe,
            ]);
            $responseData = [
                'code' => $code,
                'describe' => $describe,
                'body' => $body,
            ];
            if ($partnerId !== null) {
                $responseData['partnerId'] = $partnerId;
            }
            return response(json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200)
                ->header('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            // 构建完整的响应数据
            $responseData = [
                'code' => $code,
                'describe' => $describe,
            ];
            
            // 如果有partnerId，添加到响应中
            if ($partnerId !== null) {
                $responseData['partnerId'] = $partnerId;
            }
            
            // 如果有partnerDealId，添加到响应中
            if ($partnerDealId !== null) {
                $responseData['partnerDealId'] = $partnerDealId;
            }
            
            // 添加body字段（body中不应该包含code和describe）
            // 过滤掉body中可能存在的code和describe字段
            $cleanBody = $body;
            unset($cleanBody['code'], $cleanBody['describe']);
            
            if (!empty($cleanBody)) {
                $responseData['body'] = $cleanBody;
            }

            // 根据 encrypt 参数决定是否加密
            if ($encrypt) {
                // 将整个响应体JSON进行AES加密
                $jsonString = json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $encryptedBody = $client->encryptBody($jsonString);

                // 返回加密后的Base64字符串（作为响应体）
                // 注意：响应体是字符串，不是JSON对象
                return response($encryptedBody, 200)
                    ->header('Content-Type', 'application/json; charset=utf-8');
            } else {
                // 不加密，直接返回JSON
                return response(json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200)
                    ->header('Content-Type', 'application/json; charset=utf-8');
            }
        } catch (\Exception $e) {
            Log::error('美团响应加密失败', [
                'error' => $e->getMessage(),
                'body' => $body,
                'trace' => $e->getTraceAsString(),
            ]);
            
            // 加密失败时，返回未加密的响应（仅用于调试）
            $responseData = [
                'code' => 200,
                'describe' => 'success',
                'body' => $body,
                'error' => '响应加密失败',
            ];
            return response(json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200)
                ->header('Content-Type', 'application/json; charset=utf-8');
        }
    }

    /**
     * 处理订单创建V2（对应携程的CreatePreOrder）
     * 需要锁定库存
     */
    protected function handleOrderCreateV2(array $data): Response
    {
        try {
            // 获取partnerId（用于错误响应）
            $client = $this->getClient();
            $partnerId = $client ? $client->getPartnerId() : null;

            DB::beginTransaction();

            $otaPlatform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            if (!$otaPlatform) {
                DB::rollBack();
                return $this->errorResponse(500, 'OTA平台配置不存在', $partnerId, true);  // 订单创建V2需要加密
            }

            // 解析请求数据
            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $partnerDealId = $body['partnerDealId'] ?? '';
            $quantity = intval($body['quantity'] ?? 1);
            // 美团订单创建接口可能使用 travelDate 或 useDate
            $useDate = $body['travelDate'] ?? $body['useDate'] ?? '';
            $realNameType = intval($body['realNameType'] ?? 0);
            $credentialList = $body['credentialList'] ?? [];
            $contacts = $body['contacts'] ?? [];
            $contactInfo = !empty($contacts) ? $contacts[0] : [];
            
            // ============================================
            // ⚠️ 测试代码：景区闭园场景（测试完成后删除）
            // ============================================
            // 测试开关：通过环境变量控制，设置为 true 时模拟景区闭园
            // 测试完成后，删除以下代码块（从这行到 "测试代码结束" 之间的所有代码）
            // 查找方法：在文件中搜索 "测试场景-景区闭园" 即可找到
            // 
            // 根据美团文档，订单创建V2失败响应格式：
            // {
            //   "code": 421,
            //   "describe": "景区已闭园",
            //   "partnerId": 26465
            // }
            // 注意：订单创建V2接口是全局加密的，响应需要加密（Base64字符串）
            $testScenicClosed = env('MEITUAN_TEST_SCENIC_CLOSED', false);
            if ($testScenicClosed === true || $testScenicClosed === 'true' || $testScenicClosed === '1') {
                DB::rollBack();
                Log::info('美团订单创建V2：测试场景-景区闭园', [
                    'order_id' => $orderId,
                    'partner_deal_id' => $partnerDealId,
                ]);
                // 根据文档，订单创建V2失败响应格式：code、describe、partnerId（无body字段）
                // 接口是全局加密的，所以响应需要加密
                return $this->errorResponse(410, '购买此产品前需要先购买XXX产品', $partnerId, true);
            }
            // ============================================
            // 测试代码结束
            // ============================================
            
            // 处理游客信息（美团可能使用 visitors 字段）
            if (empty($contacts) && !empty($body['visitors'])) {
                $contacts = $body['visitors'];
                $contactInfo = !empty($contacts) ? $contacts[0] : [];
            }
            
            // 处理游客信息中的证件信息（美团可能使用 credentials 字段）
            if (empty($credentialList) && !empty($contactInfo['credentials'])) {
                $credentials = $contactInfo['credentials'];
                // 将 credentials 转换为 credentialList 格式
                $credentialList = [];
                foreach ($credentials as $key => $credentialNo) {
                    $credentialList[] = [
                        'credentialType' => 0, // 默认身份证
                        'credentialNo' => $credentialNo,
                    ];
                }
            }

            // 验证必要参数
            if (empty($orderId)) {
                DB::rollBack();
                return $this->errorResponse(400, '订单号(orderId)为空', $partnerId, true);  // 订单创建V2需要加密
            }

            if (empty($partnerDealId)) {
                DB::rollBack();
                return $this->errorResponse(400, '产品编码(partnerDealId)为空', $partnerId, true);  // 订单创建V2需要加密
            }

            if (empty($useDate)) {
                DB::rollBack();
                return $this->errorResponse(400, '使用日期(useDate)为空', $partnerId, true);  // 订单创建V2需要加密
            }

            // 根据产品编码查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                DB::rollBack();
                Log::error('美团订单创建V2：产品不存在', [
                    'partner_deal_id' => $partnerDealId,
                ]);
                return $this->errorResponse(505, '产品不存在', $partnerId, true);  // 订单创建V2需要加密
            }

            // 查找产品关联的酒店和房型（通过价格表）
            $price = $product->prices()->where('date', $useDate)->first();
            if (!$price) {
                DB::rollBack();
                Log::error('美团订单创建V2：指定日期没有价格', [
                    'product_id' => $product->id,
                    'use_date' => $useDate,
                ]);
                return $this->errorResponse(400, '指定日期没有价格', $partnerId, true);  // 订单创建V2需要加密
            }

            $roomType = $price->roomType;
            $hotel = $roomType->hotel ?? null;

            if (!$hotel || !$roomType) {
                DB::rollBack();
                Log::error('美团订单创建V2：产品未关联酒店或房型', [
                    'product_id' => $product->id,
                ]);
                return $this->errorResponse(400, '产品未关联酒店或房型', $partnerId);
            }

            // 检查库存（考虑入住天数）
            $stayDays = $product->stay_days ?: 1;
            $checkInDate = \Carbon\Carbon::parse($useDate);
            
            // 检查连续入住天数的库存是否足够
            $inventoryCheck = $this->checkInventoryForStayDays($roomType->id, $checkInDate, $stayDays, $quantity);
            if (!$inventoryCheck['success']) {
                DB::rollBack();
                return $this->errorResponse(503, $inventoryCheck['message'], $partnerId);
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
                ], $partnerId, null, 200, 'success', true);  // 订单创建V2需要加密
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

            // 构建 guest_info，确保包含 name 和 idCode（横店服务需要）
            // 横店服务期望的格式：[{name: "xxx", idCode: "xxx", cardNo: "xxx"}]
            $guestInfo = [];
            
            // 优先从 visitors 中构建客人信息（美团可能使用 visitors 字段）
            if (!empty($contacts) && is_array($contacts)) {
                foreach ($contacts as $contact) {
                    $guestName = $contact['name'] ?? $contactName;
                    $guestIdCode = '';
                    
                    // 从 contact 的 credentials 中获取证件号
                    if (!empty($contact['credentials']) && is_array($contact['credentials'])) {
                        // credentials 可能是数组，取第一个证件号
                        $guestIdCode = reset($contact['credentials']) ?: '';
                    }
                    
                    // 如果 credentials 中没有，尝试从 credentialList 中匹配
                    if (empty($guestIdCode) && !empty($credentialList)) {
                        // 如果只有一个证件，直接使用
                        if (count($credentialList) === 1) {
                            $guestIdCode = $credentialList[0]['credentialNo'] ?? '';
                        }
                    }
                    
                    // 只有当姓名或证件号至少有一个时才添加
                    if (!empty($guestName) || !empty($guestIdCode)) {
                        $guestInfo[] = [
                            'name' => $guestName,
                            'idCode' => $guestIdCode,
                            'cardNo' => $guestIdCode, // 兼容携程格式
                            'credentialType' => 0, // 默认身份证
                            'credentialNo' => $guestIdCode,
                        ];
                    }
                }
            }
            
            // 如果没有从 visitors 中获取到信息，尝试从 credentialList 构建
            if (empty($guestInfo) && !empty($credentialList)) {
                foreach ($credentialList as $credential) {
                    $credentialNo = $credential['credentialNo'] ?? '';
                    $guestName = $contactName; // 使用联系人姓名
                    
                    if (!empty($credentialNo) || !empty($guestName)) {
                        $guestInfo[] = [
                            'name' => $guestName,
                            'idCode' => $credentialNo,
                            'cardNo' => $credentialNo, // 兼容携程格式
                            'credentialType' => $credential['credentialType'] ?? 0,
                            'credentialNo' => $credentialNo,
                        ];
                    }
                }
            }
            
            // 如果还是没有信息，至少保存联系人信息
            if (empty($guestInfo) && !empty($contactName)) {
                $guestInfo[] = [
                    'name' => $contactName,
                    'idCode' => '',
                    'cardNo' => '',
                ];
            }

            Log::info('美团订单创建V2：构建客人信息', [
                'order_id' => $orderId,
                'contact_name' => $contactName,
                'credential_list_count' => count($credentialList),
                'guest_info_count' => count($guestInfo),
                'guest_info' => $guestInfo,
            ]);

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
                'guest_info' => $guestInfo, // 使用构建好的 guest_info，包含 name 和 idCode
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
                return $this->errorResponse(503, '库存锁定失败：' . $lockResult['message'], $partnerId);
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
            ], $partnerId, null, 200, 'success', true);  // 订单创建V2需要加密
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('美团订单创建V2失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            $partnerId = $this->getClient() ? $this->getClient()->getPartnerId() : null;
            return $this->errorResponse(599, '系统处理异常：' . $e->getMessage(), $partnerId, true);  // 订单创建V2需要加密
        }
    }

    /**
     * 处理订单出票（对应携程的PayPreOrder）
     */
    protected function handleOrderPay(array $data, Request $request): Response
    {
        try {
            // 获取partnerId（用于错误响应）
            $client = $this->getClient();
            $partnerId = $client ? $client->getPartnerId() : null;

            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $partnerOrderId = $body['partnerOrderId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空', $partnerId);
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在', $partnerId);
            }

            // 如果订单已经是确认状态，直接返回成功（幂等性）
            if ($order->status === OrderStatus::CONFIRMED) {
                return $this->buildOrderPaySuccessResponse($order, $orderId, $partnerId);
            }

            // 检查订单状态（必须是PAID_PENDING）
            if ($order->status !== OrderStatus::PAID_PENDING) {
                return $this->errorResponse(506, '订单状态不正确，当前状态：' . $order->status->label(), $partnerId);
            }

            // 更新支付时间
            if (!$order->paid_at) {
                $order->update(['paid_at' => now()]);
            }

            // 检查是否系统直连
            // 确保订单的关联数据已加载（用于系统直连检查）
            $order->load(['hotel.scenicSpot', 'product.softwareProvider', 'product.scenicSpot']);
            
            // 使用 ResourceServiceFactory 检查系统直连（与携程保持一致）
            $isSystemConnected = ResourceServiceFactory::isSystemConnected($order, 'order');
            
            Log::info('美团订单出票：检查系统直连状态', [
                'order_id' => $order->id,
                'is_system_connected' => $isSystemConnected,
                'hotel_id' => $order->hotel_id,
                'product_id' => $order->product_id,
            ]);

            // 先响应美团（不等待景区方接口）
            // 系统直连和非系统直连都返回 code=598（出票中）
            $code = 598; // 出票中

            // 异步处理景区方接口调用
            if ($isSystemConnected) {
                // 系统直连：异步调用景区方接口接单（超时时间已在 Job 类中定义：10秒）
                \App\Jobs\ProcessResourceOrderJob::dispatch($order, 'confirm');
                
                Log::info('美团订单出票：已派发接单Job', [
                    'order_id' => $order->id,
                ]);
            } else {
                // 非系统直连：只更新状态为确认中，等待人工接单
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::CONFIRMING,
                    '美团订单出票，等待人工接单'
                );
                
                Log::info('美团订单出票：非系统直连，等待人工接单', [
                    'order_id' => $order->id,
                ]);
            }

            // 检查请求是否加密
            // 如果请求头中有 X-Encryption-Status: encrypted，表示请求是加密的，响应也应该加密
            $requestEncrypted = $request->header('X-Encryption-Status') === 'encrypted';
            
            Log::info('美团订单出票：检查请求加密状态', [
                'order_id' => $order->id,
                'request_encrypted' => $requestEncrypted,
                'x_encryption_status' => $request->header('X-Encryption-Status'),
            ]);

            // 返回出票中响应（code=598）
            // 注意：根据美团文档，出票中时外层code应该是598，body中只包含orderId和partnerOrderId
            // 如果请求是加密的，响应也应该加密
            return $this->successResponse(
                [
                    'orderId' => intval($orderId),
                    'partnerOrderId' => $order->order_no,
                ],
                $partnerId,
                null,
                598,  // 外层code=598
                '出票中',  // 外层describe='出票中'
                $requestEncrypted  // 根据请求加密状态决定响应是否加密
            );

        } catch (\Exception $e) {
            Log::error('美团订单出票处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            $partnerId = $this->getClient() ? $this->getClient()->getPartnerId() : null;
            return $this->errorResponse(599, '系统处理异常', $partnerId);
        }
    }

    /**
     * 构建订单出票成功响应
     */
    protected function buildOrderPaySuccessResponse(Order $order, string $orderId, ?int $partnerId = null): Response
    {
        // 构建响应数据（根据美团文档，出票成功时body中不包含code和describe）
        $responseBody = [
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
        // 注意：voucherType=0 时，不需要传递voucher字段
        // credentialList的数量应该与订单数量（room_count）一致
        if ($order->real_name_type === 1 && !empty($order->credential_list)) {
            $responseBody['credentialList'] = [];
            $roomCount = $order->room_count ?? 1;
            $credentialList = $order->credential_list;
            
            // 确保credentialList的数量与订单数量一致
            // 如果credentialList数量少于订单数量，使用第一个证件信息填充
            // 如果credentialList数量多于订单数量，只取前roomCount个
            for ($i = 0; $i < $roomCount; $i++) {
                $credential = $credentialList[$i] ?? $credentialList[0] ?? null;
                if ($credential) {
                    $credentialItem = [
                        'credentialType' => $credential['credentialType'] ?? 0,
                        'credentialNo' => $credential['credentialNo'] ?? '',
                    ];
                    
                    // 只有当voucherType=1（一码一验）时才传递voucher字段
                    // voucherType=0时，不传递voucher字段（即使有值也不传）
                    // 如果voucher为空字符串，也不传递该字段
                    if (!empty($credential['voucher'])) {
                        $credentialItem['voucher'] = $credential['voucher'];
                    }
                    
                    $responseBody['credentialList'][] = $credentialItem;
                }
            }
        }

        return $this->successResponse($responseBody, $partnerId);
    }

    /**
     * 处理订单查询（对应携程的QueryOrder）
     */
    protected function handleOrderQuery(array $data): Response
    {
        try {
            // 获取partnerId（用于错误响应）
            $client = $this->getClient();
            $partnerId = $client ? $client->getPartnerId() : null;

            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空', $partnerId);
            }

            $order = Order::where('ota_order_no', (string)$orderId)
                ->with(['product', 'hotel', 'roomType'])
                ->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在', $partnerId);
            }

            // 映射订单状态到美团状态
            $orderStatus = $this->mapOrderStatusToMeituan($order->status);

            // 构建响应数据（根据美团文档，订单查询响应body中不包含code和describe）
            // 计算已使用数量和已退款数量
            $usedQuantity = 0;
            $refundedQuantity = 0;
            if ($order->status === OrderStatus::VERIFIED) {
                $usedQuantity = $order->room_count;
            } elseif ($order->status === OrderStatus::CANCEL_APPROVED) {
                $refundedQuantity = $order->room_count;
            }
            
            $responseBody = [
                'orderId' => intval($orderId),
                'partnerOrderId' => $order->order_no,
                'orderStatus' => $orderStatus,
                'orderQuantity' => $order->room_count,  // 订单总票数
                'usedQuantity' => $usedQuantity,  // 已使用数量
                'refundedQuantity' => $refundedQuantity,  // 已退款数量
                'voucherType' => 0,  // 凭证码类型：0为不需要码核销
            ];
            
            // 如果是实名制订单，添加credentialList
            if ($order->real_name_type === 1 && !empty($order->credential_list)) {
                $responseBody['realNameType'] = 1;
                $responseBody['credentialList'] = [];
                $roomCount = $order->room_count ?? 1;
                $credentialList = $order->credential_list;
                
                // 确保credentialList的数量与订单数量一致
                for ($i = 0; $i < $roomCount; $i++) {
                    $credential = $credentialList[$i] ?? $credentialList[0] ?? null;
                    if ($credential) {
                        $credentialItem = [
                            'credentialType' => $credential['credentialType'] ?? 0,
                            'credentialNo' => $credential['credentialNo'] ?? '',
                            'status' => 0,  // 0为未使用，1为已使用，2为已退款
                        ];
                        
                        // 只有当voucher不为空时才传递voucher字段
                        if (!empty($credential['voucher'])) {
                            $credentialItem['voucher'] = $credential['voucher'];
                        }
                        
                        $responseBody['credentialList'][] = $credentialItem;
                    }
                }
            }

            // 如果订单已核销，返回usedQuantity
            if ($order->status === OrderStatus::VERIFIED) {
                $responseBody['usedQuantity'] = $order->room_count;
            }

            // 如果订单已退款，返回refundedQuantity
            if ($order->status === OrderStatus::CANCEL_APPROVED) {
                $responseBody['refundedQuantity'] = $order->room_count;
            }

            return $this->successResponse($responseBody, $partnerId);
        } catch (\Exception $e) {
            Log::error('美团订单查询失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            $partnerId = $this->getClient() ? $this->getClient()->getPartnerId() : null;
            return $this->errorResponse(599, '系统处理异常', $partnerId);
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
    protected function handleOrderRefund(array $data, Request $request): Response
    {
        try {
            // 获取partnerId（用于错误响应）
            $client = $this->getClient();
            $partnerId = $client ? $client->getPartnerId() : null;

            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $refundQuantity = intval($body['refundQuantity'] ?? 0);
            // 获取退款流水号（根据文档，refundId是必填字段）
            $refundId = $body['refundId'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空', $partnerId);
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在', $partnerId);
            }

            // 检查订单状态（必须是PAID_PENDING或CONFIRMED）
            if (!in_array($order->status, [OrderStatus::PAID_PENDING, OrderStatus::CONFIRMED])) {
                return $this->errorResponse(506, '订单状态不允许退款，当前状态：' . $order->status->label(), $partnerId);
            }

            // 如果订单已使用，不允许退款
            if ($order->status === OrderStatus::VERIFIED) {
                return $this->errorResponse(506, '订单已使用，不允许退款', $partnerId);
            }

            // 如果订单已过期，不允许退款
            if ($order->check_in_date < now()->toDateString()) {
                return $this->errorResponse(506, '订单已过期，不允许退款', $partnerId);
            }

            // 验证退款数量
            if ($refundQuantity <= 0 || $refundQuantity > $order->room_count) {
                return $this->errorResponse(400, '退款数量不正确', $partnerId);
            }

            // 加载订单关联数据（用于通知）
            $order->load([
                'otaPlatform',
                'product.scenicSpot',
                'hotel',
                'roomType'
            ]);

            // 在接收到OTA退款请求时立即发送钉钉通知（在状态更新之前）
            try {
                $cancelData = [
                    'quantity' => $refundQuantity,
                    'cancel_type_label' => $refundQuantity >= $order->room_count ? '全部取消' : '部分取消',
                ];
                \App\Jobs\NotifyOrderCancelRequestedJob::dispatch($order, $cancelData);
                
                Log::info('美团订单退款：已触发钉钉通知', [
                    'order_id' => $order->id,
                    'refund_quantity' => $refundQuantity,
                ]);
            } catch (\Exception $e) {
                Log::warning('美团订单退款：触发钉钉通知失败', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
                // 通知失败不影响退款流程，继续处理
            }

            // 保存退款流水号到订单（根据美团文档，商家必须存储美团refundId）
            // 注意：这里先保存，即使后续资源方取消失败，退款流水号也需要保存，因为美团会用同一流水号查询退款进度
            if (!empty($refundId)) {
                $order->update(['refund_serial_no' => $refundId]);
                Log::info('美团订单退款：已保存退款流水号', [
                    'order_id' => $order->id,
                    'refund_id' => $refundId,
                ]);
            } else {
                Log::warning('美团订单退款：退款流水号为空', [
                    'order_id' => $order->id,
                    'body' => $body,
                ]);
            }

            // 检查是否系统直连
            // 确保订单的关联数据已加载（用于系统直连检查）
            $order->load(['hotel.scenicSpot', 'product.softwareProvider', 'product.scenicSpot']);
            
            // 使用 ResourceServiceFactory 检查系统直连（与携程保持一致）
            $isSystemConnected = ResourceServiceFactory::isSystemConnected($order, 'order');
            
            Log::info('美团订单退款：检查系统直连状态', [
                'order_id' => $order->id,
                'is_system_connected' => $isSystemConnected,
                'hotel_id' => $order->hotel_id,
                'product_id' => $order->product_id,
            ]);

            // 检查请求是否加密
            // 如果请求头中有 X-Encryption-Status: encrypted，表示请求是加密的，响应也应该加密
            $encryptResponse = $request->header('X-Encryption-Status') === 'encrypted';
            
            Log::info('美团订单退款：检查请求加密状态', [
                'order_id' => $order->id,
                'request_encrypted' => $encryptResponse,
                'x_encryption_status' => $request->header('X-Encryption-Status'),
            ]);

            // 先响应美团（不等待景区方接口）
            // 系统直连和非系统直连都返回 code=602（审批中）
            $code = 602; // 审批中

            // 异步处理景区方接口调用
            if ($isSystemConnected) {
                // 系统直连：异步调用景区方接口取消订单（超时时间已在 Job 类中定义：10秒）
                \App\Jobs\ProcessResourceCancelOrderJob::dispatch($order, '美团申请退款');
                
                Log::info('美团订单退款：已派发取消订单Job', [
                    'order_id' => $order->id,
                ]);
            } else {
                // 非系统直连：只更新状态为申请取消中，等待人工处理
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::CANCEL_REQUESTED,
                    '美团申请退款，数量：' . $refundQuantity
                );
                
                Log::info('美团订单退款：非系统直连，等待人工处理', [
                    'order_id' => $order->id,
                ]);
            }

            // 返回审批中响应（code=602）
            // 根据美团文档，退款审批中时外层code应该是602，body中包含orderId、partnerOrderId、refundId
            // 如果请求是加密的，响应也应该加密
            return $this->successResponse(
                [
                    'orderId' => intval($orderId),
                    'partnerOrderId' => $order->order_no,
                    'refundId' => $refundId,  // 根据文档，refundId是必填字段
                ],
                $partnerId,
                null,
                602,  // 外层code=602（审批中）
                '退款审批中',  // 外层describe='退款审批中'
                $encryptResponse  // 根据请求的加密状态决定响应是否加密
            );

        } catch (\Exception $e) {
            Log::error('美团订单退款处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            $partnerId = $this->getClient() ? $this->getClient()->getPartnerId() : null;
            return $this->errorResponse(599, '系统处理异常', $partnerId);
        }
    }

    /**
     * 处理已退款消息（新功能）
     */
    protected function handleOrderRefunded(array $data): Response
    {
        try {
            // 获取partnerId（用于错误响应）
            $client = $this->getClient();
            $partnerId = $client ? $client->getPartnerId() : null;

            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $refundSerialNo = $body['refundSerialNo'] ?? '';
            $refundTime = $body['refundTime'] ?? '';
            $refundMessageType = $body['refundMessageType'] ?? 0;
            $reason = $body['reason'] ?? '';

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空', $partnerId);
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在', $partnerId);
            }

            // 幂等性检查：如果订单已存在退款流水号且与请求中的相同，直接返回成功
            if ($order->refund_serial_no && !empty($refundSerialNo) && $order->refund_serial_no === $refundSerialNo) {
                return $this->successResponse(
                    [
                        'orderId' => intval($orderId),
                    ],
                    $partnerId
                );
            }

            // 构建备注信息
            $remark = '美团已退款';
            if (!empty($refundSerialNo)) {
                $remark .= '，退款流水号：' . $refundSerialNo;
            } elseif (!empty($refundTime)) {
                $remark .= '，退款时间：' . $refundTime;
            }
            if (!empty($reason)) {
                $remark .= '，原因：' . $reason;
            }

            // 更新订单状态为CANCEL_APPROVED
            $this->orderService->updateOrderStatus(
                $order,
                OrderStatus::CANCEL_APPROVED,
                $remark
            );

            // 记录退款流水号（如果提供）
            if (!empty($refundSerialNo)) {
                $order->update(['refund_serial_no' => $refundSerialNo]);
            }

            // 释放库存
            $releaseResult = $this->releaseInventoryForPreOrder($order);
            if (!$releaseResult['success']) {
                Log::warning('美团已退款消息：库存释放失败', [
                    'order_id' => $order->id,
                    'error' => $releaseResult['message'],
                ]);
            }

            return $this->successResponse(
                [
                    'orderId' => intval($orderId),
                ],
                $partnerId
            );

        } catch (\Exception $e) {
            Log::error('美团已退款消息处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            $partnerId = $this->getClient() ? $this->getClient()->getPartnerId() : null;
            return $this->errorResponse(599, '系统处理异常', $partnerId);
        }
    }

    /**
     * 处理订单关闭消息（新功能）
     */
    protected function handleOrderClose(array $data): Response
    {
        try {
            // 获取partnerId（用于错误响应）
            $client = $this->getClient();
            $partnerId = $client ? $client->getPartnerId() : null;

            $body = $data['body'] ?? $data;
            $orderId = $body['orderId'] ?? '';
            $closeType = intval($body['closeType'] ?? 0);

            if (empty($orderId)) {
                return $this->errorResponse(400, '订单号(orderId)为空', $partnerId);
            }

            $order = Order::where('ota_order_no', (string)$orderId)->first();

            if (!$order) {
                return $this->errorResponse(400, '订单不存在', $partnerId);
            }

            // 幂等性检查：如果订单状态已经是CANCEL_APPROVED，直接返回成功
            if ($order->status === OrderStatus::CANCEL_APPROVED) {
                return $this->successResponse([
                    'orderId' => intval($orderId),
                ], $partnerId);
            }

            // 更新订单状态为CANCEL_APPROVED
            $closeTypeMap = [
                1 => '用户未支付，美团关闭订单',
                2 => '合作方下单接口异常，美团关闭订单',
                3 => '合作方出票接口异常，美团出票失败且已退款',
            ];
            $closeReason = $closeTypeMap[$closeType] ?? '订单关闭';

            // 对于 closeType=3，需要根据订单状态判断
            if ($closeType === 3) {
                // 合作方出票接口异常，美团出票失败且已退款
                // 根据实际订单状态处理
                if ($order->status === OrderStatus::CONFIRMED) {
                    // 订单已出票，记录日志但直接关闭（美团已退款）
                    Log::info('美团订单关闭：订单已出票，美团已退款', [
                        'order_id' => $order->id,
                        'close_type' => $closeType,
                        'order_status' => $order->status->value,
                    ]);
                }
            }

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

            return $this->successResponse(
                [
                    'orderId' => intval($orderId),
                ],
                $partnerId
            );

        } catch (\Exception $e) {
            Log::error('美团订单关闭消息处理失败', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            $partnerId = $this->getClient() ? $this->getClient()->getPartnerId() : null;
            return $this->errorResponse(599, '系统处理异常', $partnerId);
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
    public function handleProductPriceCalendar(Request $request): Response
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
                    // 价格从"分"转换为"元"（美团接口要求单位：元，保留两位小数）
                    'mtPrice' => round(floatval($priceData['sale_price']) / 100, 2),
                    'marketPrice' => round(floatval($priceData['market_price'] ?? $priceData['sale_price']) / 100, 2),
                    'settlementPrice' => round(floatval($priceData['settlement_price']) / 100, 2),
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
    public function handleProductLevelPriceCalendarV2(Request $request): Response
    {
        try {
            // 记录美团原始请求，便于排查请求格式和加密问题
            Log::info('美团拉取多层价格日历V2原始请求', [
                'headers' => [
                    'PartnerId' => $request->header('PartnerId'),
                    'Authorization' => $request->header('Authorization'),
                    'Date' => $request->header('Date'),
                    'AppKey' => $request->header('AppKey'),
                    'X-Encryption-Status' => $request->header('X-Encryption-Status'),
                ],
                'request_all' => $request->all(),
                'raw_body' => $request->getContent(),
            ]);

            $client = $this->getClient();
            if (!$client) {
                return $this->errorResponse(500, '美团配置不存在', null);
            }

            // 获取partnerId（用于错误响应）
            $partnerId = $client->getPartnerId();

            // 获取原始请求体（美团发送的是加密的Base64字符串）
            $rawBody = $request->getContent();
            if (empty($rawBody)) {
                Log::warning('美团拉取多层价格日历V2：原始请求体为空');
                return $this->errorResponse(400, '请求体为空', $partnerId);
            }

            // 解密请求体
            try {
                $decryptedBody = $client->decryptBody($rawBody);
                Log::info('美团拉取多层价格日历V2解密后数据', [
                    'decrypted_body' => $decryptedBody,
                ]);
            } catch (\Exception $e) {
                Log::error('美团拉取多层价格日历V2解密失败', [
                    'error' => $e->getMessage(),
                    'raw_body_preview' => substr($rawBody, 0, 100),
                ]);
                return $this->errorResponse(400, '请求数据解密失败', $partnerId);
            }

            $data = json_decode($decryptedBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('美团拉取多层价格日历V2：JSON解析失败', [
                    'error' => json_last_error_msg(),
                    'decrypted_body' => $decryptedBody,
                ]);
                return $this->errorResponse(400, '请求数据格式错误', $partnerId);
            }

            $body = $data['body'] ?? $data;
            $partnerDealId = $body['partnerDealId'] ?? '';
            $startTime = $body['startTime'] ?? '';
            $endTime = $body['endTime'] ?? '';
            $asyncType = intval($body['asyncType'] ?? 0); // 0=同步，1=异步

            if (empty($partnerDealId) || empty($startTime) || empty($endTime)) {
                return $this->errorResponse(400, '参数不完整', $partnerId);
            }

            // 查找产品
            $product = \App\Models\Product::where('code', $partnerDealId)->first();
            if (!$product) {
                return $this->errorResponse(505, '产品不存在', $partnerId);
            }
            
            // 如果异步拉取，返回code=999，然后通过"多层价格日历变化通知V2"推送
            if ($asyncType === 1) {
                // TODO: 触发异步推送任务
                // 根据美团文档，异步拉取时外层code应该是999
                return $this->successResponse(
                    [],  // body为空
                    $partnerId,
                    $partnerDealId,
                    999,  // 外层code=999
                    '异步拉取，将通过通知接口推送'  // 外层describe
                );
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
                        'startTime' => '14:00',
                        'endTime' => '16:00',
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
                    // 价格从"分"转换为"元"（美团接口要求单位：元，保留两位小数）
                    'marketPrice' => round(floatval($priceData['market_price'] ?? $priceData['sale_price']) / 100, 2),
                    'mtPrice' => round(floatval($priceData['sale_price']) / 100, 2),
                    'settlementPrice' => round(floatval($priceData['settlement_price']) / 100, 2),
                    'stock' => $stock,
                    'attr' => null,
                ];
            }

            // 返回成功响应，传递 partnerId 和 partnerDealId
            return $this->successResponse($responseBody, $partnerId, $partnerDealId);
        } catch (\Exception $e) {
            Log::error('美团拉取多层价格日历V2失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $partnerId = $this->getClient() ? $this->getClient()->getPartnerId() : null;
            return $this->errorResponse(599, '系统处理异常', $partnerId);
        }
    }
}
