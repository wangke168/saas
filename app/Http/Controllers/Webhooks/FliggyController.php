<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\OrderStatus;
use App\Enums\OtaPlatform;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Services\OrderService;
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
            $data = $request->all();
            
            Log::info('飞猪产品变更通知', ['data' => $data]);

            // 验证签名
            // TODO: 根据飞猪文档实现签名验证

            // 处理产品变更
            // TODO: 实现产品变更逻辑

            return response()->json([
                'success' => true,
                'message' => '接收成功',
            ]);
        } catch (\Exception $e) {
            Log::error('飞猪产品变更通知异常', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => '处理失败',
            ], 500);
        }
    }

    /**
     * 交易主动通知（订单状态）
     */
    public function orderStatus(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            
            Log::info('飞猪订单状态通知', ['data' => $data]);

            // 验证签名
            // TODO: 根据飞猪文档实现签名验证

            $orderId = $data['orderId'] ?? '';
            $status = $data['status'] ?? '';

            $order = Order::where('ota_order_no', $orderId)->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => '订单不存在',
                ], 404);
            }

            // 根据飞猪状态更新订单状态
            $orderStatus = match($status) {
                'PAID' => OrderStatus::PAID_PENDING,
                'CANCELLED' => OrderStatus::CANCEL_APPROVED,
                default => null,
            };

            if ($orderStatus) {
                $this->orderService->updateOrderStatus($order, $orderStatus, '飞猪状态通知');
            }

            return response()->json([
                'success' => true,
                'message' => '接收成功',
            ]);
        } catch (\Exception $e) {
            Log::error('飞猪订单状态通知异常', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => '处理失败',
            ], 500);
        }
    }
}
