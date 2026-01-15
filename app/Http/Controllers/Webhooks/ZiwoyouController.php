<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ZiwoyouController extends Controller
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    /**
     * 接收自我游回调通知（统一回调地址）
     * 路由：POST /api/webhooks/ziwoyou
     * 
     * 根据 method 字段分发到不同的处理方法：
     * - confirm: 确认通知
     * - print: 出票通知
     * - cancel: 取消通知
     * - finish: 核销通知
     * - changeDate: 改期通知
     * - product: 产品通知
     */
    public function callback(Request $request): JsonResponse
    {
        $data = $request->all();
        $method = $data['method'] ?? '';
        
        Log::info('自我游回调通知', [
            'method' => $method,
            'order_source_id' => $data['orderSourceId'] ?? null,
            'order_id' => $data['orderId'] ?? null,
            'data_keys' => array_keys($data),
        ]);
        
        try {
            switch ($method) {
                case 'confirm':
                    $this->handleConfirm($data);
                    break;
                case 'print':
                    $this->handlePrint($data);
                    break;
                case 'cancel':
                    $this->handleCancel($data);
                    break;
                case 'finish':
                    $this->handleFinish($data);
                    break;
                case 'changeDate':
                    $this->handleChangeDate($data);
                    break;
                case 'product':
                    $this->handleProduct($data);
                    break;
                default:
                    Log::warning('自我游回调：未知的通知方法', [
                        'method' => $method,
                        'data' => $data,
                    ]);
            }
            
            return response()->json([
                'state' => 1,
                'msg' => '成功',
            ]);
        } catch (\Exception $e) {
            Log::error('自我游回调处理失败', [
                'method' => $method,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);
            
            return response()->json([
                'state' => 0,
                'msg' => '处理失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 处理确认通知
     * confirmState: 1=确认成功, 2=确认失败
     */
    protected function handleConfirm(array $data): void
    {
        $orderSourceId = $data['orderSourceId'] ?? '';
        $order = Order::where('order_no', $orderSourceId)
            ->orWhere('ota_order_no', $orderSourceId)
            ->first();
        
        if (!$order) {
            throw new \Exception("订单不存在：{$orderSourceId}");
        }
        
        $confirmState = $data['confirmState'] ?? 0;
        $num = $data['num'] ?? 0;
        $orderMoney = $data['orderMoney'] ?? 0;
        $saleMoney = $data['saleMoney'] ?? 0;
        
        DB::beginTransaction();
        try {
            if ($confirmState == 1) {
                // 确认成功
                $order->update([
                    'status' => OrderStatus::CONFIRMED,
                    'settlement_amount' => $orderMoney,
                    'confirmed_at' => now(),
                ]);
                
                Log::info('自我游订单确认成功', [
                    'order_id' => $order->id,
                    'order_source_id' => $orderSourceId,
                    'order_money' => $orderMoney,
                    'sale_money' => $saleMoney,
                ]);
            } elseif ($confirmState == 2) {
                // 确认失败
                $order->update([
                    'status' => OrderStatus::REJECTED,
                ]);
                
                Log::warning('自我游订单确认失败', [
                    'order_id' => $order->id,
                    'order_source_id' => $orderSourceId,
                ]);
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 处理出票通知
     * printState: 1=出票成功, 2=出票失败
     */
    protected function handlePrint(array $data): void
    {
        $orderSourceId = $data['orderSourceId'] ?? '';
        $order = Order::where('order_no', $orderSourceId)
            ->orWhere('ota_order_no', $orderSourceId)
            ->first();
        
        if (!$order) {
            throw new \Exception("订单不存在：{$orderSourceId}");
        }
        
        $printState = $data['printState'] ?? 0;
        $vouchers = $data['vouchers'] ?? [];
        
        if ($printState == 1) {
            // 出票成功，保存凭证信息
            // 可以将凭证信息保存到订单的扩展字段中（如果有的话）
            Log::info('自我游订单出票成功', [
                'order_id' => $order->id,
                'order_source_id' => $orderSourceId,
                'vouchers_count' => count($vouchers),
            ]);
            
            // 如果有凭证信息，可以保存到订单的扩展字段
            // $order->update(['extra_data' => ['vouchers' => $vouchers]]);
        } elseif ($printState == 2) {
            // 出票失败
            $printMessage = $data['printMessage'] ?? '出票失败';
            Log::error('自我游订单出票失败', [
                'order_id' => $order->id,
                'order_source_id' => $orderSourceId,
                'message' => $printMessage,
            ]);
        }
    }

    /**
     * 处理取消通知
     * cancelState: 1=同意取消, 2=拒绝取消
     */
    protected function handleCancel(array $data): void
    {
        $orderSourceId = $data['orderSourceId'] ?? '';
        $order = Order::where('order_no', $orderSourceId)
            ->orWhere('ota_order_no', $orderSourceId)
            ->first();
        
        if (!$order) {
            throw new \Exception("订单不存在：{$orderSourceId}");
        }
        
        $cancelState = $data['cancelState'] ?? 0;
        $cancelMoney = $data['cancelMoney'] ?? 0;
        $cancelNum = $data['cancelNum'] ?? 0;
        
        DB::beginTransaction();
        try {
            if ($cancelState == 1) {
                // 同意取消
                $order->update([
                    'status' => OrderStatus::CANCEL_APPROVED,
                    'cancelled_at' => now(),
                ]);
                
                Log::info('自我游订单取消成功', [
                    'order_id' => $order->id,
                    'order_source_id' => $orderSourceId,
                    'cancel_money' => $cancelMoney,
                    'cancel_num' => $cancelNum,
                ]);
            } elseif ($cancelState == 2) {
                // 拒绝取消
                $order->update([
                    'status' => OrderStatus::CANCEL_REJECTED,
                ]);
                
                Log::warning('自我游订单取消失败', [
                    'order_id' => $order->id,
                    'order_source_id' => $orderSourceId,
                ]);
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 处理核销通知
     */
    protected function handleFinish(array $data): void
    {
        $orderSourceId = $data['orderSourceId'] ?? '';
        $order = Order::where('order_no', $orderSourceId)
            ->orWhere('ota_order_no', $orderSourceId)
            ->first();
        
        if (!$order) {
            throw new \Exception("订单不存在：{$orderSourceId}");
        }
        
        $finishNum = $data['finishNum'] ?? 0;
        $finishCodes = $data['finishCodes'] ?? [];
        
        DB::beginTransaction();
        try {
            $order->update([
                'status' => OrderStatus::VERIFIED,
            ]);
            
            Log::info('自我游订单核销', [
                'order_id' => $order->id,
                'order_source_id' => $orderSourceId,
                'finish_num' => $finishNum,
                'finish_codes_count' => count($finishCodes),
            ]);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 处理改期通知
     */
    protected function handleChangeDate(array $data): void
    {
        $orderSourceId = $data['orderSourceId'] ?? '';
        $order = Order::where('order_no', $orderSourceId)
            ->orWhere('ota_order_no', $orderSourceId)
            ->first();
        
        if (!$order) {
            throw new \Exception("订单不存在：{$orderSourceId}");
        }
        
        $changeDateState = $data['changeDateState'] ?? 0;
        $changeDateMessage = $data['changeDateMessage'] ?? '';
        
        if ($changeDateState == 1) {
            // 改期成功
            Log::info('自我游订单改期成功', [
                'order_id' => $order->id,
                'order_source_id' => $orderSourceId,
            ]);
        } elseif ($changeDateState == 2) {
            // 改期失败
            Log::warning('自我游订单改期失败', [
                'order_id' => $order->id,
                'order_source_id' => $orderSourceId,
                'message' => $changeDateMessage,
            ]);
        }
    }

    /**
     * 处理产品变更通知
     * productType: 1=价格变更, 2=基本信息变更, 3=下架, 4=上架
     */
    protected function handleProduct(array $data): void
    {
        $productId = $data['productId'] ?? null;
        $productType = $data['productType'] ?? 0;
        
        Log::info('自我游产品变更通知', [
            'product_id' => $productId,
            'product_type' => $productType,
            'type_label' => match($productType) {
                1 => '价格变更',
                2 => '基本信息变更',
                3 => '下架',
                4 => '上架',
                default => '未知',
            },
        ]);
        
        // 产品变更通知可以触发产品信息同步
        // 这里只记录日志，具体同步逻辑可以根据需要实现
    }
}

