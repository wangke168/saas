<?php

namespace App\Jobs;

use App\Enums\OtaPlatform;
use App\Models\Order;
use App\Services\OTA\CtripService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyOtaOrderStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CtripService $ctripService): void
    {
        // 刷新订单对象，确保状态是最新的（因为队列任务可能使用序列化的旧对象）
        $this->order->refresh();
        
        // 重新加载关联数据，确保关联数据存在（refresh() 不会重新加载关联数据）
        $this->order->load(['otaPlatform', 'product']);
        
        \Illuminate\Support\Facades\Log::info('NotifyOtaOrderStatusJob 开始执行', [
            'order_id' => $this->order->id,
            'order_status' => $this->order->status->value,
            'ota_platform_id' => $this->order->ota_platform_id,
            'ota_platform_code' => $this->order->otaPlatform?->code?->value,
            'is_ctrip' => $this->order->otaPlatform?->code === OtaPlatform::CTRIP,
            'has_ota_platform' => $this->order->otaPlatform !== null,
        ]);
        
        // 根据订单的OTA平台通知订单状态
        if ($this->order->otaPlatform?->code === OtaPlatform::CTRIP) {
            // 订单确认通知
            if ($this->order->status->value === 'confirmed') {
                \Illuminate\Support\Facades\Log::info('准备调用携程订单确认接口', [
                    'order_id' => $this->order->id,
                    'ota_order_no' => $this->order->ota_order_no,
                    'order_no' => $this->order->order_no,
                    'ctrip_item_id' => $this->order->ctrip_item_id,
                ]);
                
                try {
                    // 传入 Order 对象，由 CtripService 构建完整的数据结构
                    $result = $ctripService->confirmOrder($this->order);
                    
                    \Illuminate\Support\Facades\Log::info('携程订单确认接口调用成功', [
                        'order_id' => $this->order->id,
                        'result' => $result,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('携程订单确认接口调用失败', [
                        'order_id' => $this->order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
            
            // 订单核销通知
            if ($this->order->status->value === 'verified') {
                $itemId = $this->order->ctrip_item_id ?: (string) $this->order->id;
                $useStartDate = $this->order->check_in_date->format('Y-m-d');
                $useEndDate = $this->order->check_out_date->format('Y-m-d');
                $quantity = $this->order->room_count;
                $useQuantity = $this->order->room_count; // 假设全部核销
                
                // 从 guest_info 中提取出行人信息
                $passengers = [];
                if (!empty($this->order->guest_info) && is_array($this->order->guest_info)) {
                    foreach ($this->order->guest_info as $guest) {
                        if (isset($guest['passengerId'])) {
                            $passengers[] = ['passengerId' => $guest['passengerId']];
                        }
                    }
                }
                
                $ctripService->notifyOrderConsumed(
                    $this->order->ota_order_no,
                    $this->order->order_no,
                    $itemId,
                    $useStartDate,
                    $useEndDate,
                    $quantity,
                    $useQuantity,
                    $passengers
                );
            }
            
            // 订单取消确认通知
            \Illuminate\Support\Facades\Log::info('检查订单取消确认条件', [
                'order_id' => $this->order->id,
                'order_status_value' => $this->order->status->value,
                'is_cancel_approved' => $this->order->status->value === 'cancel_approved',
            ]);
            
            if ($this->order->status->value === 'cancel_approved') {
                \Illuminate\Support\Facades\Log::info('准备调用携程订单取消确认接口', [
                    'order_id' => $this->order->id,
                    'ota_order_no' => $this->order->ota_order_no,
                    'order_no' => $this->order->order_no,
                    'ctrip_item_id' => $this->order->ctrip_item_id,
                ]);
                
                try {
                    // 传入 Order 对象，由 CtripService 构建完整的数据结构
                    $result = $ctripService->confirmCancelOrder($this->order);
                    
                    \Illuminate\Support\Facades\Log::info('携程订单取消确认接口调用成功', [
                        'order_id' => $this->order->id,
                        'result' => $result,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('携程订单取消确认接口调用失败', [
                        'order_id' => $this->order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }
        
        // TODO: 实现其他OTA平台的通知逻辑
    }
}

            
            // 订单核销通知
            if ($this->order->status->value === 'verified') {
                $itemId = $this->order->ctrip_item_id ?: (string) $this->order->id;
                $useStartDate = $this->order->check_in_date->format('Y-m-d');
                $useEndDate = $this->order->check_out_date->format('Y-m-d');
                $quantity = $this->order->room_count;
                $useQuantity = $this->order->room_count; // 假设全部核销
                
                // 从 guest_info 中提取出行人信息
                $passengers = [];
                if (!empty($this->order->guest_info) && is_array($this->order->guest_info)) {
                    foreach ($this->order->guest_info as $guest) {
                        if (isset($guest['passengerId'])) {
                            $passengers[] = ['passengerId' => $guest['passengerId']];
                        }
                    }
                }
                
                $ctripService->notifyOrderConsumed(
                    $this->order->ota_order_no,
                    $this->order->order_no,
                    $itemId,
                    $useStartDate,
                    $useEndDate,
                    $quantity,
                    $useQuantity,
                    $passengers
                );
            }
            
            // 订单取消确认通知
            \Illuminate\Support\Facades\Log::info('检查订单取消确认条件', [
                'order_id' => $this->order->id,
                'order_status_value' => $this->order->status->value,
                'is_cancel_approved' => $this->order->status->value === 'cancel_approved',
            ]);
            
            if ($this->order->status->value === 'cancel_approved') {
                \Illuminate\Support\Facades\Log::info('准备调用携程订单取消确认接口', [
                    'order_id' => $this->order->id,
                    'ota_order_no' => $this->order->ota_order_no,
                    'order_no' => $this->order->order_no,
                    'ctrip_item_id' => $this->order->ctrip_item_id,
                ]);
                
                try {
                    // 传入 Order 对象，由 CtripService 构建完整的数据结构
                    $result = $ctripService->confirmCancelOrder($this->order);
                    
                    \Illuminate\Support\Facades\Log::info('携程订单取消确认接口调用成功', [
                        'order_id' => $this->order->id,
                        'result' => $result,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('携程订单取消确认接口调用失败', [
                        'order_id' => $this->order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }
        
        // TODO: 实现其他OTA平台的通知逻辑
    }
}

            
            // 订单核销通知
            if ($this->order->status->value === 'verified') {
                $itemId = $this->order->ctrip_item_id ?: (string) $this->order->id;
                $useStartDate = $this->order->check_in_date->format('Y-m-d');
                $useEndDate = $this->order->check_out_date->format('Y-m-d');
                $quantity = $this->order->room_count;
                $useQuantity = $this->order->room_count; // 假设全部核销
                
                // 从 guest_info 中提取出行人信息
                $passengers = [];
                if (!empty($this->order->guest_info) && is_array($this->order->guest_info)) {
                    foreach ($this->order->guest_info as $guest) {
                        if (isset($guest['passengerId'])) {
                            $passengers[] = ['passengerId' => $guest['passengerId']];
                        }
                    }
                }
                
                $ctripService->notifyOrderConsumed(
                    $this->order->ota_order_no,
                    $this->order->order_no,
                    $itemId,
                    $useStartDate,
                    $useEndDate,
                    $quantity,
                    $useQuantity,
                    $passengers
                );
            }
            
            // 订单取消确认通知
            \Illuminate\Support\Facades\Log::info('检查订单取消确认条件', [
                'order_id' => $this->order->id,
                'order_status_value' => $this->order->status->value,
                'is_cancel_approved' => $this->order->status->value === 'cancel_approved',
            ]);
            
            if ($this->order->status->value === 'cancel_approved') {
                \Illuminate\Support\Facades\Log::info('准备调用携程订单取消确认接口', [
                    'order_id' => $this->order->id,
                    'ota_order_no' => $this->order->ota_order_no,
                    'order_no' => $this->order->order_no,
                    'ctrip_item_id' => $this->order->ctrip_item_id,
                ]);
                
                try {
                    // 传入 Order 对象，由 CtripService 构建完整的数据结构
                    $result = $ctripService->confirmCancelOrder($this->order);
                    
                    \Illuminate\Support\Facades\Log::info('携程订单取消确认接口调用成功', [
                        'order_id' => $this->order->id,
                        'result' => $result,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('携程订单取消确认接口调用失败', [
                        'order_id' => $this->order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }
        
        // TODO: 实现其他OTA平台的通知逻辑
    }
}

            
            // 订单核销通知
            if ($this->order->status->value === 'verified') {
                $itemId = $this->order->ctrip_item_id ?: (string) $this->order->id;
                $useStartDate = $this->order->check_in_date->format('Y-m-d');
                $useEndDate = $this->order->check_out_date->format('Y-m-d');
                $quantity = $this->order->room_count;
                $useQuantity = $this->order->room_count; // 假设全部核销
                
                // 从 guest_info 中提取出行人信息
                $passengers = [];
                if (!empty($this->order->guest_info) && is_array($this->order->guest_info)) {
                    foreach ($this->order->guest_info as $guest) {
                        if (isset($guest['passengerId'])) {
                            $passengers[] = ['passengerId' => $guest['passengerId']];
                        }
                    }
                }
                
                $ctripService->notifyOrderConsumed(
                    $this->order->ota_order_no,
                    $this->order->order_no,
                    $itemId,
                    $useStartDate,
                    $useEndDate,
                    $quantity,
                    $useQuantity,
                    $passengers
                );
            }
            
            // 订单取消确认通知
            \Illuminate\Support\Facades\Log::info('检查订单取消确认条件', [
                'order_id' => $this->order->id,
                'order_status_value' => $this->order->status->value,
                'is_cancel_approved' => $this->order->status->value === 'cancel_approved',
            ]);
            
            if ($this->order->status->value === 'cancel_approved') {
                \Illuminate\Support\Facades\Log::info('准备调用携程订单取消确认接口', [
                    'order_id' => $this->order->id,
                    'ota_order_no' => $this->order->ota_order_no,
                    'order_no' => $this->order->order_no,
                    'ctrip_item_id' => $this->order->ctrip_item_id,
                ]);
                
                try {
                    // 传入 Order 对象，由 CtripService 构建完整的数据结构
                    $result = $ctripService->confirmCancelOrder($this->order);
                    
                    \Illuminate\Support\Facades\Log::info('携程订单取消确认接口调用成功', [
                        'order_id' => $this->order->id,
                        'result' => $result,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('携程订单取消确认接口调用失败', [
                        'order_id' => $this->order->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }
        
        // TODO: 实现其他OTA平台的通知逻辑
    }
}