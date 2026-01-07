<?php

namespace App\Services\OTA\Notifications;

use App\Contracts\OtaNotificationInterface;
use App\Models\Order;
use App\Services\OTA\CtripService;
use Illuminate\Support\Facades\Log;

/**
 * 携程订单状态通知服务
 */
class CtripNotificationService implements OtaNotificationInterface
{
    public function __construct(
        protected CtripService $ctripService
    ) {}

    /**
     * 通知订单确认（出票成功）
     */
    public function notifyOrderConfirmed(Order $order): void
    {
        Log::info('CtripNotificationService: 准备通知携程订单确认', [
            'order_id' => $order->id,
            'ota_order_no' => $order->ota_order_no,
            'order_no' => $order->order_no,
        ]);

        try {
            $result = $this->ctripService->confirmOrder($order);
            
            // 检查返回值，确保通知成功
            if (isset($result['success']) && $result['success'] === false) {
                $errorMessage = $result['message'] ?? '未知错误';
                Log::error('CtripNotificationService: 携程订单确认通知失败（返回值检查）', [
                    'order_id' => $order->id,
                    'error' => $errorMessage,
                    'result' => $result,
                ]);
                throw new \Exception('携程订单确认通知失败：' . $errorMessage);
            }
            
            Log::info('CtripNotificationService: 携程订单确认通知成功', [
                'order_id' => $order->id,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('CtripNotificationService: 携程订单确认通知失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 通知订单退款（取消确认）
     */
    public function notifyOrderRefunded(Order $order): void
    {
        Log::info('CtripNotificationService: 准备通知携程订单取消确认', [
            'order_id' => $order->id,
            'ota_order_no' => $order->ota_order_no,
            'order_no' => $order->order_no,
        ]);

        try {
            $result = $this->ctripService->confirmCancelOrder($order);
            
            Log::info('CtripNotificationService: 携程订单取消确认通知成功', [
                'order_id' => $order->id,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('CtripNotificationService: 携程订单取消确认通知失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 通知订单核销（已使用）
     */
    public function notifyOrderConsumed(Order $order, array $data = []): void
    {
        $itemId = $order->ctrip_item_id ?: (string) $order->id;
        $useStartDate = $order->check_in_date->format('Y-m-d');
        $useEndDate = $order->check_out_date->format('Y-m-d');
        $quantity = $order->room_count;
        $useQuantity = $data['useQuantity'] ?? $order->room_count;
        
        // 从 guest_info 中提取出行人信息
        $passengers = [];
        if (!empty($order->guest_info) && is_array($order->guest_info)) {
            foreach ($order->guest_info as $guest) {
                if (isset($guest['passengerId'])) {
                    $passengers[] = ['passengerId' => $guest['passengerId']];
                }
            }
        }

        Log::info('CtripNotificationService: 准备通知携程订单核销', [
            'order_id' => $order->id,
            'ota_order_no' => $order->ota_order_no,
            'item_id' => $itemId,
        ]);

        try {
            $this->ctripService->notifyOrderConsumed(
                $order->ota_order_no,
                $order->order_no,
                $itemId,
                $useStartDate,
                $useEndDate,
                $quantity,
                $useQuantity,
                $passengers
            );
            
            Log::info('CtripNotificationService: 携程订单核销通知成功', [
                'order_id' => $order->id,
            ]);
        } catch (\Exception $e) {
            Log::error('CtripNotificationService: 携程订单核销通知失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}

