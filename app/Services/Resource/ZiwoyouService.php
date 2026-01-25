<?php

namespace App\Services\Resource;

use App\Http\Client\ZiwoyouClient;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Models\ResourceConfig;
use App\Services\ZiwoyouProductMappingService;
use App\Enums\ExceptionOrderType;
use App\Enums\ExceptionOrderStatus;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Log;

class ZiwoyouService implements ResourceServiceInterface
{
    protected ?ZiwoyouClient $client = null;
    protected ?ResourceConfig $config = null;
    protected ZiwoyouProductMappingService $mappingService;

    public function __construct(ZiwoyouProductMappingService $mappingService)
    {
        $this->mappingService = $mappingService;
    }

    /**
     * 设置资源配置（由 ResourceServiceFactory 调用）
     */
    public function setConfig(ResourceConfig $config): self
    {
        // 确保 softwareProvider 关系已加载（解决队列序列化后关系丢失的问题）
        if (!$config->relationLoaded('softwareProvider') && $config->software_provider_id) {
            $config->load('softwareProvider');
        }
        
        $this->config = $config;
        // 重置客户端，以便使用新配置
        $this->client = null;
        return $this;
    }

    /**
     * 获取客户端
     */
    protected function getClient(): ZiwoyouClient
    {
        if ($this->client === null) {
            if (!$this->config) {
                throw new \Exception('ZiwoyouService: 配置未设置，请先调用 setConfig()');
            }
            $this->client = new ZiwoyouClient($this->config);
        }
        return $this->client;
    }

    /**
     * 接单（确认订单）
     */
    public function confirmOrder(Order $order): array
    {
        Log::info('ZiwoyouService::confirmOrder 开始', [
            'order_id' => $order->id,
            'resource_order_no' => $order->resource_order_no,
        ]);
        
        try {
            // 如果订单已经有资源方订单号，说明已经接单，直接返回成功
            if ($order->resource_order_no) {
                Log::info('ZiwoyouService::confirmOrder: 订单已有资源方订单号，直接返回成功', [
                    'order_id' => $order->id,
                    'resource_order_no' => $order->resource_order_no,
                ]);
                
                return [
                    'success' => true,
                    'message' => '订单已确认',
                    'data' => ['resource_order_no' => $order->resource_order_no],
                ];
            }

            // 1. 获取自我游产品ID
            Log::info('ZiwoyouService::confirmOrder: 开始获取自我游产品ID', [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'hotel_id' => $order->hotel_id,
                'room_type_id' => $order->room_type_id,
            ]);
            
            $ziwoyouProductId = $this->mappingService->getZiwoyouProductId(
                $order->product_id,
                $order->hotel_id,
                $order->room_type_id
            );
            
            if (!$ziwoyouProductId) {
                Log::error('ZiwoyouService::confirmOrder: 未找到自我游产品映射关系', [
                    'order_id' => $order->id,
                    'product_id' => $order->product_id,
                    'hotel_id' => $order->hotel_id,
                    'room_type_id' => $order->room_type_id,
                ]);
                throw new \Exception('未找到自我游产品映射关系');
            }
            
            Log::info('ZiwoyouService::confirmOrder: 获取自我游产品ID成功', [
                'order_id' => $order->id,
                'ziwoyou_product_id' => $ziwoyouProductId,
            ]);
            
            // 2. 构建订单请求数据
            $requestData = $this->buildOrderRequest($order, $ziwoyouProductId);
            
            // 记录构建的请求数据（用于调试）
            Log::info('ZiwoyouService::confirmOrder: 构建订单请求数据完成', [
                'order_id' => $order->id,
                'request_data_keys' => array_keys($requestData),
                'linkCreditNo' => $requestData['linkCreditNo'] ?? 'not_set',
                'linkCreditType' => $requestData['linkCreditType'] ?? 'not_set',
                'linkMan' => $requestData['linkMan'] ?? 'not_set',
                'linkPhone' => $requestData['linkPhone'] ?? 'not_set',
                'has_peoples' => !empty($requestData['peoples']),
                'peoples_count' => !empty($requestData['peoples']) ? count($requestData['peoples']) : 0,
            ]);
            
            // 3. 可选：订单校验（根据实际情况决定是否调用）
            $shouldValidate = $this->shouldValidateOrder($order);
            if ($shouldValidate) {
                Log::info('ZiwoyouService::confirmOrder: 开始调用自我游订单校验接口', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no ?? $order->ota_order_no,
                    'ziwoyou_product_id' => $ziwoyouProductId,
                    'request_data' => $requestData,
                ]);
                
                $validateResult = $this->getClient()->validateOrder($requestData);
                
                Log::info('ZiwoyouService::confirmOrder: 自我游订单校验接口响应', [
                    'order_id' => $order->id,
                    'success' => $validateResult['success'] ?? false,
                    'state' => $validateResult['state'] ?? null,
                    'msg' => $validateResult['msg'] ?? '',
                ]);
                
                if (!($validateResult['success'] ?? false) || ($validateResult['state'] ?? 0) !== 0) {
                    $errorMsg = $validateResult['msg'] ?? '订单校验失败';
                    throw new \Exception('订单校验失败：' . $errorMsg);
                }
                
                Log::info('ZiwoyouService::confirmOrder: 订单校验通过', [
                    'order_id' => $order->id,
                ]);
            }
            
            // 4. 创建订单
            Log::info('ZiwoyouService::confirmOrder: 开始调用自我游创建订单接口', [
                'order_id' => $order->id,
                'order_no' => $order->order_no ?? $order->ota_order_no,
                'ziwoyou_product_id' => $ziwoyouProductId,
                'request_data' => $requestData,
            ]);
            
            $result = $this->getClient()->createOrder($requestData);
            
            Log::info('ZiwoyouService::confirmOrder: 自我游创建订单接口响应', [
                'order_id' => $order->id,
                'success' => $result['success'] ?? false,
                'state' => $result['state'] ?? null,
                'msg' => $result['msg'] ?? '',
                'response_data' => $result['data'] ?? null,
            ]);
            
            // 检查响应是否真正成功
            // 根据自我游接口文档和实际返回：
            // - state=0 表示接口调用成功
            // - state=1 表示订单创建成功但需要支付（待支付状态）
            // - state=-1 表示接口调用失败
            // 关键判断依据：有 orderId 且 state >= 0 且 msg 不包含错误信息
            $msg = $result['msg'] ?? '';
            $data = $result['data'] ?? null;
            $state = $result['state'] ?? -1;
            
            // 严格检查：必须有 orderId 才算成功（订单创建成功的标志）
            $hasOrderId = !empty($data['orderId']);
            
            // 检查是否有有效数据（不能是 null 或空数组）
            $hasValidData = !empty($data) && is_array($data) && !empty($data);
            
            // 检查错误消息
            $isErrorMsg = $this->isErrorMessage($msg);
            
            // 检查成功消息（msg中包含"成功"关键词）
            $isSuccessMsg = !empty($msg) && (
                mb_strpos($msg, '成功') !== false || 
                mb_strpos($msg, '下单成功') !== false ||
                mb_strpos($msg, '创建成功') !== false ||
                mb_strpos($msg, '录入订单成功') !== false
            );
            
            // 综合判断：订单创建成功的条件
            // 1. 有 orderId（最关键，订单创建成功的标志）
            // 2. state >= 0（state=-1 表示错误，0 和 1 都可以是成功）
            // 3. 没有错误消息，或者有成功消息
            // 注意：自我游接口可能返回 success=false（因为 state=1），但订单实际创建成功
            // 从自我游后台日志看，state=1 且 msg="下单成功" 且有 orderId 表示订单创建成功
            $isRealSuccess = $hasOrderId 
                && $state >= 0  // state >= 0 表示不是错误状态（0=成功，1=待支付也是成功）
                && (!$isErrorMsg || $isSuccessMsg); // 没有错误消息，或者有成功消息
            
            // 详细记录判断过程
            Log::info('ZiwoyouService::confirmOrder: 判断订单是否真正成功', [
                'order_id' => $order->id,
                'result_success' => $result['success'] ?? false,
                'result_state' => $state,
                'msg' => $msg,
                'is_error_msg' => $isErrorMsg,
                'is_success_msg' => $isSuccessMsg,
                'has_valid_data' => $hasValidData,
                'has_order_id' => $hasOrderId,
                'order_id_value' => $data['orderId'] ?? null,
                'order_state' => $data['orderState'] ?? null,
                'data' => $data,
                'is_real_success' => $isRealSuccess,
            ]);
            
            // 提取订单数据（无论是否判断为成功，都提取以便后续处理）
                $ziwoyouOrderId = $data['orderId'] ?? null;
                $orderState = $data['orderState'] ?? null;
                $payType = $data['payType'] ?? null;
                $orderMoney = $data['orderMoney'] ?? 0;
                
            // 记录订单数据提取情况
            Log::info('ZiwoyouService::confirmOrder: 提取订单数据', [
                'order_id' => $order->id,
                'is_real_success' => $isRealSuccess,
                'ziwoyou_order_id' => $ziwoyouOrderId,
                'order_state' => $orderState,
                'pay_type' => $payType,
                'order_money' => $orderMoney,
                'should_call_pay' => ($orderState == 1 && $ziwoyouOrderId),
            ]);
            
            if ($isRealSuccess) {
                // 保存自我游订单号
                if ($ziwoyouOrderId) {
                    $order->update([
                        'resource_order_no' => (string)$ziwoyouOrderId,
                        'settlement_amount' => $orderMoney, // 保存结算金额
                    ]);
                    
                    Log::info('ZiwoyouService::confirmOrder: 已保存自我游订单号', [
                        'order_id' => $order->id,
                        'ziwoyou_order_id' => $ziwoyouOrderId,
                        'order_money' => $orderMoney,
                    ]);
                }
                
                // 处理订单状态和支付
                // orderState: 0=等待人工确认, 1=待支付, 2=已成功（现付返佣）
                // payType: 0=虚拟支付（预存款）, 1=信用支付, 3=库存支付, 4=全球货仓抵扣
                
                // 如果订单状态为待支付（orderState=1），需要调用支付接口
                if ($orderState == 1 && $ziwoyouOrderId) {
                    Log::info('ZiwoyouService::confirmOrder: 订单状态为待支付，开始调用支付接口', [
                        'order_id' => $order->id,
                        'ziwoyou_order_id' => $ziwoyouOrderId,
                        'order_state' => $orderState,
                        'pay_type' => $payType,
                    ]);
                    
                    try {
                        Log::info('ZiwoyouService::confirmOrder: 准备调用支付接口', [
                            'order_id' => $order->id,
                            'ziwoyou_order_id' => $ziwoyouOrderId,
                            'client_class' => get_class($this->getClient()),
                        ]);
                        
                        $payResult = $this->getClient()->payOrder($ziwoyouOrderId);
                        
                        Log::info('ZiwoyouService::confirmOrder: 支付接口响应', [
                            'order_id' => $order->id,
                            'ziwoyou_order_id' => $ziwoyouOrderId,
                            'pay_success' => $payResult['success'] ?? false,
                            'pay_state' => $payResult['state'] ?? null,
                            'pay_msg' => $payResult['msg'] ?? '',
                            'pay_data' => $payResult['data'] ?? null,
                            'pay_result_full' => $payResult,
                        ]);
                        
                        // 检查支付是否真正成功
                        // 即使 success=true 和 state=0，如果 msg 中包含错误信息，也应该判断为失败
                        $payMsg = $payResult['msg'] ?? '';
                        $payIsErrorMsg = $this->isErrorMessage($payMsg);
                        
                        // 检查支付特定的错误关键词（如"余额不足"、"系统执行错误"等）
                        $payHasError = $payIsErrorMsg || (
                            !empty($payMsg) && (
                                mb_strpos($payMsg, '系统执行错误') !== false ||
                                mb_strpos($payMsg, '余额不足') !== false ||
                                mb_strpos($payMsg, '无法完成支付') !== false ||
                                mb_strpos($payMsg, '支付失败') !== false
                            )
                        );
                        
                        $payIsSuccess = ($payResult['success'] ?? false) 
                            && ($payResult['state'] ?? -1) === 0 
                            && !$payHasError; // 没有错误消息
                        
                        Log::info('ZiwoyouService::confirmOrder: 判断支付是否成功', [
                            'order_id' => $order->id,
                            'ziwoyou_order_id' => $ziwoyouOrderId,
                            'pay_success' => $payResult['success'] ?? false,
                            'pay_state' => $payResult['state'] ?? null,
                            'pay_msg' => $payMsg,
                            'pay_is_error_msg' => $payIsErrorMsg,
                            'pay_has_error' => $payHasError,
                            'pay_is_success' => $payIsSuccess,
                        ]);
                        
                        if ($payIsSuccess) {
                            // 支付成功，订单状态应该变为已成功
                            Log::info('ZiwoyouService::confirmOrder: 支付成功', [
                                'order_id' => $order->id,
                                'ziwoyou_order_id' => $ziwoyouOrderId,
                                'pay_result' => $payResult,
                            ]);
                            
                            // 更新订单状态为已确认
                            $order->update(['status' => OrderStatus::CONFIRMED]);
                            $orderState = 2; // 更新为已成功状态
                        } else {
                            // 支付失败，记录错误但不抛出异常（订单已创建成功）
                            $payErrorMsg = $payResult['msg'] ?? '支付失败';
                            Log::warning('ZiwoyouService::confirmOrder: 支付失败，订单已创建但未支付', [
                                'order_id' => $order->id,
                                'ziwoyou_order_id' => $ziwoyouOrderId,
                                'pay_error' => $payErrorMsg,
                                'pay_success' => $payResult['success'] ?? false,
                                'pay_state' => $payResult['state'] ?? null,
                                'pay_has_error' => $payHasError,
                                'pay_result' => $payResult,
                            ]);
                            
                            // 创建异常订单，转人工处理支付
                            $this->createExceptionOrder($order, 'payOrder', "订单创建成功但支付失败：{$payErrorMsg}");
                        }
                    } catch (\Exception $e) {
                        // 支付接口调用异常，记录错误但不抛出异常（订单已创建成功）
                        Log::error('ZiwoyouService::confirmOrder: 支付接口调用异常', [
                            'order_id' => $order->id,
                            'ziwoyou_order_id' => $ziwoyouOrderId,
                            'error' => $e->getMessage(),
                            'error_class' => get_class($e),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        
                        // 创建异常订单，转人工处理支付
                        $this->createExceptionOrder($order, 'payOrder', '支付接口调用异常：' . $e->getMessage());
                    }
                } elseif ($payType == 0) {
                    // payType=0 表示虚拟支付（预存款），理论上已从余额扣款
                    // 但如果 orderState=2，说明已经是已成功状态，不需要再支付
                    Log::info('ZiwoyouService::confirmOrder: 虚拟支付（预存款），未调用支付接口', [
                        'order_id' => $order->id,
                        'ziwoyou_order_id' => $ziwoyouOrderId,
                        'order_state' => $orderState,
                        'pay_type' => $payType,
                        'order_money' => $orderMoney,
                        'reason' => $orderState == 1 ? 'orderState=1但payType=0' : 'orderState=' . $orderState,
                    ]);
                } else {
                    Log::info('ZiwoyouService::confirmOrder: 未调用支付接口', [
                        'order_id' => $order->id,
                        'ziwoyou_order_id' => $ziwoyouOrderId,
                        'order_state' => $orderState,
                        'pay_type' => $payType,
                        'reason' => $orderState != 1 ? 'orderState不等于1' : 'ziwoyouOrderId为空',
                    ]);
                }
                
                // 根据 orderState 更新订单状态
                if ($orderState == 2) {
                    // 已成功（现付返佣产品或支付成功）
                    $order->update(['status' => OrderStatus::CONFIRMED]);
                }
                // orderState == 0 时，等待人工确认回调
                
                return [
                    'success' => true,
                    'message' => '订单创建成功',
                    'data' => [
                        'resource_order_no' => (string)$ziwoyouOrderId,
                        'order_state' => $orderState,
                        'pay_type' => $payType,
                        'order_money' => $orderMoney,
                    ],
                ];
            } else {
                // 订单创建失败，转人工操作
                // 优先使用 msg 中的错误信息，如果没有则使用默认消息
                $errorMsg = $msg ?: ($result['msg'] ?? '订单创建失败');
                
                // 如果 state=0 但 msg 包含错误信息，说明是特殊情况（如IP白名单）
                if (($result['state'] ?? 0) === 0 && $this->isErrorMessage($msg)) {
                    $errorMsg = "自我游接口返回 state=0 但包含错误信息：{$msg}";
                }
                
                Log::error('ZiwoyouService::confirmOrder: 订单创建失败，转人工操作', [
                    'order_id' => $order->id,
                    'error' => $errorMsg,
                    'state' => $result['state'] ?? null,
                    'msg' => $msg,
                    'has_data' => !empty($result['data']),
                    'has_order_id' => !empty($data['orderId']),
                    'ziwoyou_order_id' => $ziwoyouOrderId,
                    'order_state' => $orderState,
                    'will_not_call_pay' => true,
                    'result' => $result,
                ]);
                
                // 创建异常订单，转人工操作
                $this->createExceptionOrder($order, 'confirmOrder', $errorMsg);
                
                return [
                    'success' => false,
                    'message' => '订单创建失败：' . $errorMsg,
                    'need_manual' => true, // 标记需要人工处理
                ];
            }
        } catch (\Exception $e) {
            Log::error('ZiwoyouService::confirmOrder: 接单失败，转人工操作', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // 自我游接单失败，创建异常订单，转人工操作
            $this->createExceptionOrder($order, 'confirmOrder', $e->getMessage());
            
            return [
                'success' => false,
                'message' => '接单失败：' . $e->getMessage(),
                'need_manual' => true, // 标记需要人工处理
            ];
        }
    }

    /**
     * 构建订单请求数据
     */
    protected function buildOrderRequest(Order $order, string $ziwoyouProductId): array
    {
        // 基础订单信息
        $requestData = [
            'infoId' => (int)$ziwoyouProductId,
            'orderSourceId' => $order->order_no ?? $order->ota_order_no,
            'num' => $order->room_count ?? 1,
            'travelDate' => $order->check_in_date?->format('Y-m-d') ?? '',
        ];
        
        // 联系人信息
        // 联系人姓名：只从 guest_info[0]['name'] 获取（携程订单格式）
        $contactName = '';
        if (!empty($order->guest_info) && is_array($order->guest_info)) {
            $firstGuest = $order->guest_info[0] ?? [];
            $contactName = $firstGuest['name'] ?? $firstGuest['Name'] ?? '';
        }
        
        if ($contactName) {
            // 分离姓名（简单处理：如果有空格，第一个词是姓，其余是名）
            $nameParts = explode(' ', $contactName, 2);
            if (count($nameParts) == 2) {
                $requestData['lastName'] = $nameParts[0];
                $requestData['firstName'] = $nameParts[1];
            } else {
                // 如果没有空格，全部作为名，姓为空
                $requestData['firstName'] = $contactName;
                $requestData['lastName'] = '';
            }
            
            $requestData['linkMan'] = $contactName;
        }
        
        if ($order->contact_phone) {
            $requestData['linkPhone'] = $order->contact_phone;
        }
        
        if ($order->contact_email) {
            $requestData['linkEmail'] = $order->contact_email;
        }
        
        // 证件信息（联系人证件信息，自我游接口要求必填）
        // 优先级：1. credential_list 2. guest_info[0] 3. card_no 字段
        $linkCreditNo = '';
        $linkCreditType = 0;
        $creditSource = 'none'; // 记录证件号码来源，用于调试
        
        // 1. 优先从 credential_list 获取
        // if (!empty($order->credential_list) && is_array($order->credential_list)) {
        //     $firstCredential = $order->credential_list[0] ?? [];
        //     if (!empty($firstCredential)) {
        //         $linkCreditNo = $firstCredential['credentialNo'] ?? $firstCredential['idCode'] ?? '';
        //         if (!empty($linkCreditNo)) {
        //             $linkCreditType = $this->mapCredentialType($firstCredential['credentialType'] ?? 0);
        //             $creditSource = 'credential_list';
        //         }
        //     }
        // }
        
        // 2. 如果 credential_list 中没有，从 guest_info 的第一个元素获取（携程订单格式）
        // 添加调试日志，追踪执行流程
        Log::info('ZiwoyouService::buildOrderRequest: 开始从guest_info获取证件号码', [
            'order_id' => $order->id,
            'linkCreditNo_empty' => empty($linkCreditNo),
            'has_guest_info' => !empty($order->guest_info),
            'guest_info_is_array' => is_array($order->guest_info),
            'guest_info_count' => is_array($order->guest_info) ? count($order->guest_info) : 0,
        ]);
        
        if (empty($linkCreditNo) && !empty($order->guest_info) && is_array($order->guest_info)) {
            $firstGuest = $order->guest_info[0] ?? [];
            
            Log::info('ZiwoyouService::buildOrderRequest: 获取firstGuest', [
                'order_id' => $order->id,
                'first_guest_empty' => empty($firstGuest),
                'first_guest_is_array' => is_array($firstGuest),
                'first_guest_keys' => is_array($firstGuest) ? array_keys($firstGuest) : [],
                'first_guest_data' => $firstGuest,
            ]);
            
            if (!empty($firstGuest)) {
                // 携程格式：cardNo 为身份证号码，cardType "1" 表示身份证
                // 尝试多种字段名，并去除首尾空格
                $cardNo = '';
                
                Log::info('ZiwoyouService::buildOrderRequest: 检查cardNo字段', [
                    'order_id' => $order->id,
                    'has_cardNo_key' => isset($firstGuest['cardNo']),
                    'cardNo_value' => $firstGuest['cardNo'] ?? 'not_set',
                    'cardNo_type' => isset($firstGuest['cardNo']) ? gettype($firstGuest['cardNo']) : 'not_set',
                    'cardNo_isset' => isset($firstGuest['cardNo']),
                    'cardNo_empty_check' => isset($firstGuest['cardNo']) ? empty($firstGuest['cardNo']) : 'not_set',
                    'cardNo_trimmed_empty' => isset($firstGuest['cardNo']) ? empty(trim((string)$firstGuest['cardNo'])) : 'not_set',
                ]);
                
                if (isset($firstGuest['cardNo']) && !empty(trim((string)$firstGuest['cardNo']))) {
                    $cardNo = trim((string)$firstGuest['cardNo']);
                    Log::info('ZiwoyouService::buildOrderRequest: 成功获取cardNo', [
                        'order_id' => $order->id,
                        'cardNo_raw' => $firstGuest['cardNo'],
                        'cardNo_trimmed' => $cardNo,
                    ]);
                } else {
                    Log::warning('ZiwoyouService::buildOrderRequest: cardNo获取失败', [
                        'order_id' => $order->id,
                        'isset_cardNo' => isset($firstGuest['cardNo']),
                        'cardNo_value' => $firstGuest['cardNo'] ?? 'not_set',
                        'cardNo_trimmed_empty' => isset($firstGuest['cardNo']) ? empty(trim((string)$firstGuest['cardNo'])) : 'not_set',
                    ]);
                }
                
                if (!empty($cardNo)) {
                    $linkCreditNo = $cardNo;
                    // 如果 cardType 是 "1"，映射为自我游的 0（身份证）
                    if (!empty($firstGuest['cardType'])) {
                        $cardType = (string)$firstGuest['cardType'];
                        $linkCreditType = ($cardType === '1') ? 0 : $this->mapCredentialType($firstGuest['credentialType'] ?? 0);
                    } else {
                        $linkCreditType = $this->mapCredentialType($firstGuest['credentialType'] ?? 0);
                    }
                    $creditSource = 'guest_info';
                    
                    // 详细日志记录
                    Log::info('ZiwoyouService::buildOrderRequest: 从guest_info获取证件号码', [
                        'order_id' => $order->id,
                        'guest_info_count' => count($order->guest_info),
                        'first_guest_keys' => array_keys($firstGuest),
                        'cardNo_raw' => $firstGuest['cardNo'] ?? 'not_set',
                        'cardNo_trimmed' => $cardNo,
                        'cardType' => $firstGuest['cardType'] ?? 'not_set',
                        'linkCreditNo' => $linkCreditNo,
                        'linkCreditType' => $linkCreditType,
                    ]);
                } else {
                    // 记录为什么没有获取到证件号码
                    Log::warning('ZiwoyouService::buildOrderRequest: guest_info中未找到有效的证件号码', [
                        'order_id' => $order->id,
                        'first_guest_keys' => array_keys($firstGuest),
                        'cardNo' => $firstGuest['cardNo'] ?? 'not_set',
                        'cardNo_type' => isset($firstGuest['cardNo']) ? gettype($firstGuest['cardNo']) : 'not_set',
                        'cardNo_empty' => isset($firstGuest['cardNo']) ? empty($firstGuest['cardNo']) : 'not_set',
                        'cardNo_isset' => isset($firstGuest['cardNo']),
                    ]);
                }
            } else {
                Log::warning('ZiwoyouService::buildOrderRequest: firstGuest为空', [
                    'order_id' => $order->id,
                    'guest_info_count' => count($order->guest_info),
                ]);
            }
        } else {
            Log::warning('ZiwoyouService::buildOrderRequest: 未进入guest_info获取逻辑', [
                'order_id' => $order->id,
                'linkCreditNo_empty' => empty($linkCreditNo),
                'has_guest_info' => !empty($order->guest_info),
                'guest_info_is_array' => is_array($order->guest_info),
            ]);
        }
        
        // 3. 如果还没有，尝试从 card_no 字段获取
        if (empty($linkCreditNo) && !empty($order->card_no)) {
            $linkCreditNo = trim((string)$order->card_no);
            if (!empty($linkCreditNo)) {
                $linkCreditType = 0; // 默认身份证
                $creditSource = 'card_no';
            }
        }
        
        // 4. 如果仍然没有证件号码，记录警告并抛出异常（自我游接口要求必填）
        if (empty($linkCreditNo)) {
            Log::error('ZiwoyouService::buildOrderRequest: 无法获取联系人证件号码', [
                'order_id' => $order->id,
                'has_credential_list' => !empty($order->credential_list),
                'credential_list_count' => is_array($order->credential_list) ? count($order->credential_list) : 0,
                'has_guest_info' => !empty($order->guest_info),
                'guest_info_count' => is_array($order->guest_info) ? count($order->guest_info) : 0,
                'guest_info_first' => !empty($order->guest_info) && is_array($order->guest_info) ? ($order->guest_info[0] ?? null) : null,
                'has_card_no' => !empty($order->card_no),
                'card_no' => $order->card_no,
            ]);
            throw new \Exception('订单缺少联系人证件号码，无法创建自我游订单');
        }
        
        // 设置联系人证件信息（确保去除首尾空格）
        $linkCreditNo = trim((string)$linkCreditNo);
        $requestData['linkCreditNo'] = $linkCreditNo;
        $requestData['linkCreditType'] = $linkCreditType;
        
        // 记录证件信息设置情况
        Log::info('ZiwoyouService::buildOrderRequest: 设置联系人证件信息', [
            'order_id' => $order->id,
            'credit_source' => $creditSource,
            'linkCreditNo' => $linkCreditNo,
            'linkCreditNo_length' => strlen($linkCreditNo),
            'linkCreditType' => $linkCreditType,
        ]);
        
        // 订单备注
        if ($order->remark) {
            $requestData['orderMemo'] = $order->remark;
        }
        
        // 酒店产品需要传 details（入住日期）
        if ($order->check_in_date) {
            $requestData['details'] = [
                [
                    'cond' => $order->check_in_date->format('Y-m-d'),
                    'num' => $order->room_count ?? 1,
                    // 订单价格存储为元，自我游接口要求单位为元，直接使用
                    'price' => floatval($order->settlement_amount ?? $order->total_amount ?? 0),
                ],
            ];
        }
        
        // 游玩人信息（如果有）
        if (!empty($order->guest_info) && is_array($order->guest_info)) {
            $peoples = [];
            foreach ($order->guest_info as $index => $guest) {
                // 支持多种格式：
                // 1. 携程格式：name, cardNo, cardType
                // 2. 其他格式：name/Name, credentialNo/IdCode/idCode, credentialType
                $guestName = $guest['name'] ?? $guest['Name'] ?? '';
                // $guestPhone = $guest['phone'] ?? $guest['Phone'] ?? $guest['mobile'] ?? '';
                $guestPhone=$order->contact_phone;
                
                // 获取证件号码，优先使用 cardNo（携程格式），并去除首尾空格
                // $guestCreditNo = '';
                // if (isset($guest['cardNo']) && !empty(trim((string)$guest['cardNo']))) {
                //     $guestCreditNo = trim((string)$guest['cardNo']);
                // } elseif (isset($guest['credentialNo']) && !empty(trim((string)$guest['credentialNo']))) {
                //     $guestCreditNo = trim((string)$guest['credentialNo']);
                // } elseif (isset($guest['IdCode']) && !empty(trim((string)$guest['IdCode']))) {
                //     $guestCreditNo = trim((string)$guest['IdCode']);
                // } elseif (isset($guest['idCode']) && !empty(trim((string)$guest['idCode']))) {
                //     $guestCreditNo = trim((string)$guest['idCode']);
                // }
                $guestCreditNo=$guest['cardNo']; 
                // 证件类型：携程格式 cardType "1" 表示身份证，对应自我游的 0
                $guestCreditType = 0; // 默认身份证
                if (!empty($guest['cardType'])) {
                    $cardType = (string)$guest['cardType'];
                    $guestCreditType = ($cardType === '1') ? 0 : $this->mapCredentialType($guest['credentialType'] ?? 0);
                } elseif (isset($guest['credentialType'])) {
                    $guestCreditType = $this->mapCredentialType($guest['credentialType']);
                }
                
                // 如果证件号码为空，记录警告但仍然添加（可能自我游接口会使用联系人的证件号码）
                if (empty($guestCreditNo)) {
                    Log::warning('ZiwoyouService::buildOrderRequest: 游玩人证件号码为空', [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'guest_name' => $guestName,
                        'guest_data_keys' => array_keys($guest),
                        'cardNo_raw' => $guest['cardNo'] ?? 'not_set',
                        'cardNo_type' => isset($guest['cardNo']) ? gettype($guest['cardNo']) : 'not_set',
                    ]);
                }
                
                $peoples[] = [
                    'linkMan' => $guestName,
                    'linkPhone' => $guestPhone,
                    'linkCreditNo' => $guestCreditNo,
                    'linkCreditType' => $guestCreditType,
                    'roomNum' => $index + 1, // 第几间房，从1开始
                ];
            }
            
            if (!empty($peoples)) {
                $requestData['peoples'] = $peoples;
            }
        }
        
        return $requestData;
    }

    /**
     * 映射证件类型
     * 自我游证件类型：0 身份证 1 学生证 2 军官证 3 护照 ...
     */
    protected function mapCredentialType(int $credentialType): int
    {
        // 简单映射：如果类型在0-21范围内，直接使用；否则默认为0（身份证）
        if ($credentialType >= 0 && $credentialType <= 21) {
            return $credentialType;
        }
        return 0; // 默认身份证
    }

    /**
     * 判断是否需要订单校验
     * 可以根据产品类型、配置等决定
     */
    protected function shouldValidateOrder(Order $order): bool
    {
        // 默认不校验，可以根据实际情况调整
        // 例如：酒店产品需要校验，其他产品不需要
        return false;
    }

    /**
     * 判断消息是否为错误信息
     * 即使 state=0，如果 msg 中包含错误关键词，也应该判断为失败
     * 
     * @param string $msg 响应消息
     * @return bool
     */
    protected function isErrorMessage(string $msg): bool
    {
        if (empty($msg)) {
            return false;
        }
        
        // 错误关键词列表
        $errorKeywords = [
            '失败',
            '下单失败',  // 新增：明确的下单失败消息
            '错误',
            '异常',
            '白名单',
            '不在白名单',
            'IP不在白名单',
            '未授权',
            '无权限',
            '拒绝',
            '不允许',
            '无效',
            '不存在',
            '不正确',  // 新增：如"账号或密钥不正确"
            '超时',
            '网络错误',
        ];
        
        foreach ($errorKeywords as $keyword) {
            if (mb_strpos($msg, $keyword) !== false) {
                Log::warning('ZiwoyouService: 检测到错误消息关键词', [
                    'msg' => $msg,
                    'keyword' => $keyword,
                ]);
                return true;
            }
        }
        
        return false;
    }

    /**
     * 取消订单
     */
    public function cancelOrder(Order $order, string $reason): array
    {
        Log::info('ZiwoyouService::cancelOrder 开始', [
            'order_id' => $order->id,
            'resource_order_no' => $order->resource_order_no,
            'reason' => $reason,
            'room_count' => $order->room_count,
        ]);
        
        try {
            if (!$order->resource_order_no) {
                throw new \Exception('订单没有资源方订单号，无法取消');
            }
            
            $ziwoyouOrderId = (int)$order->resource_order_no;
            
            // 获取取消数量，使用订单的房间数量（必须大于0）
            // cancelNum 来源：$order->room_count（订单的房间数量）
            $roomCount = $order->room_count;
            $roomCountInt = (int)($roomCount ?? 1);
            $cancelNum = max(1, $roomCountInt);
            
            Log::info('ZiwoyouService::cancelOrder: 准备调用取消接口', [
                'order_id' => $order->id,
                'ziwoyou_order_id' => $ziwoyouOrderId,
                'room_count_raw' => $roomCount,
                'room_count_type' => gettype($roomCount),
                'room_count_int' => $roomCountInt,
                'cancel_num' => $cancelNum,
                'cancel_num_type' => gettype($cancelNum),
            ]);
            
            $result = $this->getClient()->cancelOrder($ziwoyouOrderId, $reason, $cancelNum);
            
            Log::info('ZiwoyouService::cancelOrder: 取消接口响应', [
                'order_id' => $order->id,
                'ziwoyou_order_id' => $ziwoyouOrderId,
                'result_success' => $result['success'] ?? false,
                'result_state' => $result['state'] ?? null,
                'result_msg' => $result['msg'] ?? '',
                'result_data' => $result['data'] ?? null,
                'cancel_state' => $result['data']['cancelState'] ?? null,
            ]);
            
            // 检查取消是否真正成功
            // 根据自我游接口文档：cancelState=1 表示取消成功，2=审核中
            // 关键判断依据：
            // 1. success=true 且 state=0（接口调用成功）
            // 2. cancelState=1（必须存在且等于1，才是取消成功）
            // 3. msg 不包含错误信息
            // 注意：如果 data 为 null，说明取消失败，不应该判断为成功
            $cancelData = $result['data'] ?? null;
            $cancelState = $cancelData['cancelState'] ?? null;
            $msg = $result['msg'] ?? '';
            $isErrorMsg = $this->isErrorMessage($msg);
            
            // 检查是否有取消状态相关的错误消息
            $hasCancelError = !empty($msg) && (
                mb_strpos($msg, '取消数量必须大于0') !== false ||
                mb_strpos($msg, '取消失败') !== false ||
                mb_strpos($msg, '无法取消') !== false ||
                mb_strpos($msg, '系统执行错误') !== false
            );
            
            // 如果 data 为 null，说明取消失败（自我游返回错误时 data 为 null）
            $hasValidData = $cancelData !== null && is_array($cancelData);
            
            // 判断取消是否成功：必须同时满足所有条件
            // 1. 接口调用成功（success=true 且 state=0）
            // 2. 有有效数据（data 不为 null，这是关键！）
            // 3. cancelState=1（取消成功，必须存在且等于1）
            // 4. 没有错误消息
            // 注意：即使 success=true 和 state=0，如果 data 为 null 或 cancelState != 1，也应该判断为失败
            $isCancelSuccess = ($result['success'] ?? false) 
                && ($result['state'] ?? -1) === 0 
                && $hasValidData  // 必须有有效数据（data 不为 null）
                && $cancelState !== null  // cancelState 必须存在
                && $cancelState == 1  // cancelState=1 表示取消成功（必须等于1）
                && !$isErrorMsg 
                && !$hasCancelError;
            
            Log::info('ZiwoyouService::cancelOrder: 判断取消是否成功', [
                'order_id' => $order->id,
                'ziwoyou_order_id' => $ziwoyouOrderId,
                'result_success' => $result['success'] ?? false,
                'result_state' => $result['state'] ?? null,
                'has_valid_data' => $hasValidData,
                'cancel_state' => $cancelState,
                'msg' => $msg,
                'is_error_msg' => $isErrorMsg,
                'has_cancel_error' => $hasCancelError,
                'condition_1_success' => ($result['success'] ?? false),
                'condition_2_state_0' => ($result['state'] ?? -1) === 0,
                'condition_3_has_valid_data' => $hasValidData,
                'condition_4_cancel_state_1' => $cancelState == 1,
                'condition_5_no_error_msg' => !$isErrorMsg,
                'condition_6_no_cancel_error' => !$hasCancelError,
                'is_cancel_success' => $isCancelSuccess,
            ]);
            
            if ($isCancelSuccess) {
                Log::info('ZiwoyouService::cancelOrder: 取消成功', [
                    'order_id' => $order->id,
                    'ziwoyou_order_id' => $ziwoyouOrderId,
                    'cancel_state' => $cancelState,
                    'cancel_id' => $cancelData['cancelId'] ?? null,
                    'cancel_money' => $cancelData['cancelMoney'] ?? null,
                ]);
                
                return [
                    'success' => true,
                    'message' => '订单取消成功',
                    'data' => $cancelData ?? [],
                ];
            } else {
                // 取消失败，记录详细错误信息
                $errorMsg = $msg ?: '订单取消失败';
                if ($cancelState && $cancelState != 1) {
                    $errorMsg = "取消状态异常：cancelState={$cancelState}，{$msg}";
                }
                
                Log::error('ZiwoyouService::cancelOrder: 取消失败', [
                    'order_id' => $order->id,
                    'ziwoyou_order_id' => $ziwoyouOrderId,
                    'error' => $errorMsg,
                    'result_success' => $result['success'] ?? false,
                    'result_state' => $result['state'] ?? null,
                    'cancel_state' => $cancelState,
                    'result' => $result,
                ]);
                
                throw new \Exception($errorMsg);
            }
        } catch (\Exception $e) {
            Log::error('ZiwoyouService::cancelOrder: 取消失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->createExceptionOrder($order, 'cancelOrder', $e->getMessage());
            
            return [
                'success' => false,
                'message' => '取消失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询订单是否可以取消
     */
    public function canCancelOrder(Order $order): array
    {
        try {
            // 查询订单状态
            $result = $this->queryOrderStatus($order);
            
            if (!($result['success'] ?? false)) {
                // 查询失败，返回可以取消（由后续取消接口判断）
                return [
                    'can_cancel' => true,
                    'message' => '查询订单状态失败，允许尝试取消',
                    'data' => ['query_result' => $result],
                ];
            }
            
            $orderData = $result['data'] ?? [];
            $status = $orderData['status'] ?? 'unknown';
            
            // 根据订单状态判断是否可以取消
            // 自我游订单状态：0=新订单, 1=已确认, 2=已成功, 3=已取消, 4=已完成
            // 只有状态为0或1时可以取消
            $canCancel = in_array($status, ['0', '1', 'new', 'confirmed']);
            
            return [
                'can_cancel' => $canCancel,
                'message' => $canCancel ? '可以取消' : '订单状态不允许取消',
                'data' => [
                    'status' => $status,
                    'order_data' => $orderData,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ZiwoyouService::canCancelOrder: 查询失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            
            // 查询失败，返回可以取消（由后续取消接口判断）
            return [
                'can_cancel' => true,
                'message' => '查询失败，允许尝试取消',
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * 查询订单状态
     */
    public function queryOrderStatus($orderOrOrderNo): array
    {
        try {
            // 获取订单号
            $orderSourceId = $orderOrOrderNo instanceof Order 
                ? ($orderOrOrderNo->order_no ?? $orderOrOrderNo->ota_order_no) 
                : $orderOrOrderNo;
            $order = $orderOrOrderNo instanceof Order ? $orderOrOrderNo : null;
            
            // 获取自我游订单号（如果有）
            $ziwoyouOrderId = null;
            if ($order && $order->resource_order_no) {
                $ziwoyouOrderId = (int)$order->resource_order_no;
            }
            
            // 调用查询接口
            $result = $this->getClient()->queryOrder($orderSourceId, $ziwoyouOrderId);
            
            if (!($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $result['msg'] ?? '查询订单状态失败',
                    'data' => [],
                ];
            }
            
            $responseData = $result['data'] ?? null;
            if (!$responseData) {
                return [
                    'success' => false,
                    'message' => '查询结果数据为空',
                    'data' => [],
                ];
            }
            
            // 提取订单状态
            // orderState: 0=新订单, 1=已确认, 2=已成功, 3=已取消, 4=已完成
            $orderState = $responseData['orderState'] ?? null;
            $mappedStatus = $this->mapOrderState($orderState);
            
            // 构建返回数据
            $data = [
                'order_no' => $orderSourceId,
                'status' => $mappedStatus,
                'verified_at' => null,
                'use_start_date' => $responseData['travelDate'] ?? null,
                'use_end_date' => $responseData['endTravelDate'] ?? null,
                'use_quantity' => $responseData['finishNum'] ?? null,
                'passengers' => [],
                'vouchers' => [],
            ];
            
            // 提取游玩人信息
            if (!empty($responseData['peoples']) && is_array($responseData['peoples'])) {
                $data['passengers'] = array_map(function($people) {
                    return [
                        'name' => ($people['linkMan'] ?? '') ?: (($people['lastName'] ?? '') . ($people['firstName'] ?? '')),
                        'idCode' => $people['linkCreditNo'] ?? '',
                        'credentialType' => $people['linkCreditType'] ?? 0,
                        'phone' => $people['linkPhone'] ?? '',
                    ];
                }, $responseData['peoples']);
            }
            
            // 提取凭证信息
            if (!empty($responseData['vouchers']) && is_array($responseData['vouchers'])) {
                $data['vouchers'] = $responseData['vouchers'];
            }
            
            // 如果订单已完成（orderState=4），提取核销信息
            if ($orderState == 4) {
                $data['verified_at'] = $responseData['cancelDate'] ?? null; // 使用取消日期作为核销时间（如果有其他字段，可以调整）
            }
            
            Log::info('ZiwoyouService::queryOrderStatus: 查询成功', [
                'order_source_id' => $orderSourceId,
                'order_state' => $orderState,
                'mapped_status' => $mappedStatus,
            ]);
            
            return [
                'success' => true,
                'message' => '查询成功',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('ZiwoyouService::queryOrderStatus: 查询异常', [
                'order_or_order_no' => $orderOrOrderNo instanceof Order ? $orderOrOrderNo->id : $orderOrOrderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'message' => '查询订单状态异常：' . $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * 映射订单状态
     * orderState: 0=新订单, 1=已确认, 2=已成功, 3=已取消, 4=已完成
     */
    protected function mapOrderState(?int $orderState): string
    {
        return match($orderState) {
            0 => 'confirming',      // 新订单（等待确认）
            1 => 'confirmed',      // 已确认
            2 => 'confirmed',      // 已成功（现付返佣）
            3 => 'cancel_approved', // 已取消
            4 => 'verified',       // 已完成（已核销）
            default => 'unknown',
        };
    }

    /**
     * 拒单（拒绝订单）
     * 自我游可能没有专门的拒单接口，通过取消订单实现
     */
    public function rejectOrder(Order $order, string $reason): array
    {
        Log::info('ZiwoyouService::rejectOrder: 通过取消订单实现拒单', [
            'order_id' => $order->id,
            'reason' => $reason,
        ]);
        
        // 自我游没有专门的拒单接口，通过取消订单实现
        return $this->cancelOrder($order, '拒单：' . $reason);
    }

    /**
     * 核销订单
     * 自我游可能没有专门的核销接口，核销通过回调通知处理
     */
    public function verifyOrder(Order $order, array $data): array
    {
        Log::info('ZiwoyouService::verifyOrder: 自我游核销通过回调通知处理', [
            'order_id' => $order->id,
            'data' => $data,
        ]);
        
        // 自我游核销通过回调通知处理，这里返回成功
        return [
            'success' => true,
            'message' => '核销通过回调通知处理',
            'data' => $data,
        ];
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(Order $order, string $operation, string $message): void
    {
        ExceptionOrder::create([
            'order_id' => $order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => "自我游{$operation}失败：{$message}",
            'exception_data' => [
                'operation' => $operation,
                'resource_provider' => 'ziwoyou',
            ],
            'status' => ExceptionOrderStatus::PENDING,
        ]);
    }
}

