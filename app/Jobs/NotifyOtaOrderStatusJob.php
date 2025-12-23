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
        // 根据订单的OTA平台通知订单状态
        if ($this->order->otaPlatform?->code === OtaPlatform::CTRIP) {
            // 订单确认通知
            if ($this->order->status->value === 'confirmed' && $this->order->resource_order_no) {
                $ctripService->confirmOrder($this->order->ota_order_no, $this->order->resource_order_no);
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
        }
        
        // TODO: 实现其他OTA平台的通知逻辑
    }
}
