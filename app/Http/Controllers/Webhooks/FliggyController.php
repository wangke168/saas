<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\OrderStatus;
use App\Enums\OtaPlatform;
use App\Enums\ExceptionOrderType;
use App\Enums\ExceptionOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Client\FliggyDistributionClient;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\ResourceConfig;
use App\Services\OrderService;
use App\Services\FliggyOrderStatusMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FliggyController extends Controller
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    /**
     * 产品变更通知
     */
    public function productChange(Request $request): JsonResponse
    {
        try {
            // 1. 验证签名
            if (!$this->verifySignature($request)) {
                return response()->json([
                    'success' => false,
                    'message' => '签名验证失败',
                ], 403);
            }
            
            $data = $request->all();
            $pushType = $data['pushType'] ?? '';
            
            Log::info('飞猪产品变更通知', [
                'push_type' => $pushType,
                'data' => $data,
            ]);

            // 2. 根据推送类型处理
            return $this->handleProductChange($data);
            
        } catch (\Exception $e) {
            Log::error('飞猪产品变更通知异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => '处理失败',
            ], 500);
        }
    }
    
    /**
     * 处理产品变更
     */
    protected function handleProductChange(array $data): JsonResponse
    {
        $pushType = $data['pushType'] ?? '';
        $productId = $data['productId'] ?? '';
        $changedTime = $data['changedTime'] ?? null;
        
        Log::info('FliggyController: 处理产品变更', [
            'push_type' => $pushType,
            'product_id' => $productId,
            'changed_time' => $changedTime,
        ]);
        
        // 根据推送类型处理
        // VACATION_RESOURCE_INFO_CHANGE - 基础信息变更
        // VACATION_RESOURCE_VALID_CHANGE - 生效
        // VACATION_RESOURCE_INVALID_CHANGE - 失效
        // VACATION_RESOURCE_PRICE_STOCK_CHANGE - 价库变更
        
        // 目前只记录日志，后续可以根据需要实现具体的处理逻辑
        // 例如：主动调用查询接口更新本地数据
        
        return response()->json([
            'success' => true,
            'message' => '接收成功',
        ]);
    }

    /**
     * 交易主动通知（订单状态）
     */
    public function orderStatus(Request $request): JsonResponse
    {
        try {
            // 1. 验证签名
            if (!$this->verifySignature($request)) {
                return response()->json([
                    'success' => false,
                    'message' => '签名验证失败',
                ], 403);
            }
            
            $data = $request->all();
            $pushType = $data['pushType'] ?? '';
            
            Log::info('飞猪订单状态通知', [
                'push_type' => $pushType,
                'data' => $data,
            ]);

            // 2. 根据推送类型分发处理
            return match($pushType) {
                'ORDER_STATUS_CHANGE' => $this->handleOrderStatusChange($data),
                'ORDER_SEND_CODE_NOTIFY' => $this->handleSendCodeNotify($data),
                'ORDER_REFUND_NOTIFY' => $this->handleRefundNotify($data),
                'ORDER_VERIFY_NOTIFY' => $this->handleVerifyNotify($data),
                default => response()->json([
                    'success' => false,
                    'message' => '未知的推送类型：' . $pushType,
                ], 400),
            };
            
        } catch (\Exception $e) {
            Log::error('飞猪订单状态通知异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => '处理失败',
            ], 500);
        }
    }
    
    /**
     * 处理订单状态变更
     */
    protected function handleOrderStatusChange(array $data): JsonResponse
    {
        $orderId = $data['orderId'] ?? '';
        $outOrderId = $data['outOrderId'] ?? '';
        $orderStatus = $data['orderStatus'] ?? null;
        
        // 优先通过 resource_order_no 查找，其次通过 order_no
        $order = null;
        if ($orderId) {
            $order = Order::where('resource_order_no', $orderId)->first();
        }
        if (!$order && $outOrderId) {
            $order = Order::where('order_no', $outOrderId)->first();
        }
        
        if (!$order) {
            Log::warning('FliggyController: 订单状态推送 - 订单不存在', [
                'order_id' => $orderId,
                'out_order_id' => $outOrderId,
            ]);
            return response()->json([
                'success' => false,
                'message' => '订单不存在',
            ], 404);
        }
        
        Log::info('FliggyController: 处理订单状态变更', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'fliggy_order_id' => $orderId,
            'fliggy_status' => $orderStatus,
        ]);
        
        // 映射状态
        $ourStatus = FliggyOrderStatusMapper::mapToOurStatus($orderStatus ?? 0);
        
        if ($ourStatus) {
            $this->orderService->updateOrderStatus($order, $ourStatus, '飞猪状态推送');
            
            Log::info('FliggyController: 订单状态更新成功', [
                'order_id' => $order->id,
                'fliggy_status' => $orderStatus,
                'our_status' => $ourStatus->value,
            ]);
        } elseif ($orderStatus == 1004) {
            // 出票失败，创建异常订单
            ExceptionOrder::create([
                'order_id' => $order->id,
                'exception_type' => ExceptionOrderType::API_ERROR,
                'exception_message' => '飞猪订单出票失败',
                'exception_data' => $data,
                'status' => ExceptionOrderStatus::PENDING,
            ]);
            
            Log::warning('FliggyController: 订单出票失败，已创建异常订单', [
                'order_id' => $order->id,
                'fliggy_status' => $orderStatus,
            ]);
        } else {
            Log::warning('FliggyController: 无法映射的订单状态', [
                'order_id' => $order->id,
                'fliggy_status' => $orderStatus,
            ]);
        }
        
        return response()->json([
            'success' => true,
            'message' => '接收成功',
        ]);
    }
    
    /**
     * 处理发码通知
     */
    protected function handleSendCodeNotify(array $data): JsonResponse
    {
        $orderId = $data['orderId'] ?? '';
        $outOrderId = $data['outOrderId'] ?? '';
        $codeInfos = $data['codeInfos'] ?? [];
        
        // 查找订单
        $order = null;
        if ($orderId) {
            $order = Order::where('resource_order_no', $orderId)->first();
        }
        if (!$order && $outOrderId) {
            $order = Order::where('order_no', $outOrderId)->first();
        }
        
        if (!$order) {
            Log::warning('FliggyController: 发码通知 - 订单不存在', [
                'order_id' => $orderId,
                'out_order_id' => $outOrderId,
            ]);
            return response()->json([
                'success' => false,
                'message' => '订单不存在',
            ], 404);
        }
        
        Log::info('FliggyController: 处理发码通知', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'code_count' => count($codeInfos),
        ]);
        
        // 保存码信息到订单备注或其他字段（根据业务需求）
        // 目前只记录日志
        
        return response()->json([
            'success' => true,
            'message' => '接收成功',
        ]);
    }
    
    /**
     * 处理退款通知
     */
    protected function handleRefundNotify(array $data): JsonResponse
    {
        $orderId = $data['orderId'] ?? '';
        $outOrderId = $data['outOrderId'] ?? '';
        $refundStatus = $data['refundStatus'] ?? '';
        $refundAmount = $data['refundAmount'] ?? 0;
        
        // 查找订单
        $order = null;
        if ($orderId) {
            $order = Order::where('resource_order_no', $orderId)->first();
        }
        if (!$order && $outOrderId) {
            $order = Order::where('order_no', $outOrderId)->first();
        }
        
        if (!$order) {
            Log::warning('FliggyController: 退款通知 - 订单不存在', [
                'order_id' => $orderId,
                'out_order_id' => $outOrderId,
            ]);
            return response()->json([
                'success' => false,
                'message' => '订单不存在',
            ], 404);
        }
        
        Log::info('FliggyController: 处理退款通知', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'refund_status' => $refundStatus,
            'refund_amount' => $refundAmount,
        ]);
        
        // 根据退款状态更新订单状态
        // 目前只记录日志，后续可以根据业务需求实现
        
        return response()->json([
            'success' => true,
            'message' => '接收成功',
        ]);
    }
    
    /**
     * 处理核销通知
     */
    protected function handleVerifyNotify(array $data): JsonResponse
    {
        $orderId = $data['orderId'] ?? '';
        $outOrderId = $data['outOrderId'] ?? '';
        $verifyTime = $data['verifyTime'] ?? null;
        $useQuantity = $data['useQuantity'] ?? 0;
        
        // 查找订单
        $order = null;
        if ($orderId) {
            $order = Order::where('resource_order_no', $orderId)->first();
        }
        if (!$order && $outOrderId) {
            $order = Order::where('order_no', $outOrderId)->first();
        }
        
        if (!$order) {
            Log::warning('FliggyController: 核销通知 - 订单不存在', [
                'order_id' => $orderId,
                'out_order_id' => $outOrderId,
            ]);
            return response()->json([
                'success' => false,
                'message' => '订单不存在',
            ], 404);
        }
        
        Log::info('FliggyController: 处理核销通知', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'verify_time' => $verifyTime,
            'use_quantity' => $useQuantity,
        ]);
        
        // 更新订单状态为已核销
        $this->orderService->updateOrderStatus($order, OrderStatus::VERIFIED, '飞猪核销通知');
        
        return response()->json([
            'success' => true,
            'message' => '接收成功',
        ]);
    }
    
    /**
     * 验证推送签名
     */
    protected function verifySignature(Request $request): bool
    {
        // 1. 获取签名参数
        $sign = $request->input('sign');
        $timestamp = $request->input('timestamp');
        $distributorId = $request->input('distributorId');
        
        if (empty($sign) || empty($timestamp) || empty($distributorId)) {
            Log::warning('FliggyController: 推送签名验证失败 - 缺少必要参数', [
                'has_sign' => !empty($sign),
                'has_timestamp' => !empty($timestamp),
                'has_distributorId' => !empty($distributorId),
            ]);
            return false;
        }
        
        // 2. 获取配置（根据 distributorId 查找）
        $config = ResourceConfig::whereHas('softwareProvider', function ($query) {
            $query->where('api_type', 'fliggy_distribution');
        })
        ->whereJsonContains('extra_config->distributor_id', $distributorId)
        ->first();
        
        if (!$config) {
            Log::error('FliggyController: 推送签名验证失败 - 未找到配置', [
                'distributor_id' => $distributorId,
            ]);
            return false;
        }
        
        $extraConfig = $config->extra_config ?? [];
        $publicKey = $extraConfig['public_key'] ?? '';
        
        if (empty($publicKey)) {
            Log::error('FliggyController: 推送签名验证失败 - 未配置公钥', [
                'distributor_id' => $distributorId,
            ]);
            return false;
        }
        
        // 3. 构建签名字符串（根据推送类型不同，签名公式可能不同）
        // 默认使用：distributorId_timestamp
        $signString = $this->buildPushSignString($request);
        
        // 4. 验证签名
        $client = new FliggyDistributionClient($config);
        $isValid = $client->verify($signString, $sign, $publicKey);
        
        if (!$isValid) {
            Log::warning('FliggyController: 推送签名验证失败', [
                'distributor_id' => $distributorId,
                'timestamp' => $timestamp,
                'sign_preview' => substr($sign, 0, 20) . '...',
            ]);
        }
        
        return $isValid;
    }
    
    /**
     * 构建推送签名字符串
     */
    protected function buildPushSignString(Request $request): string
    {
        // 根据推送类型，签名公式可能不同
        // 默认使用：distributorId_timestamp
        $distributorId = $request->input('distributorId');
        $timestamp = $request->input('timestamp');
        
        return $distributorId . '_' . $timestamp;
    }
}
