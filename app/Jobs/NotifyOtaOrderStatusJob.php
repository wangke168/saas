<?php

namespace App\Jobs;

use App\Enums\OtaPlatform;
use App\Models\Order;
use App\Services\OTA\NotificationFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyOtaOrderStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 60;

    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    ) {
        // 使用专门的队列，确保高优先级处理
        $this->onQueue('ota-notification');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // 刷新订单对象，确保状态是最新的（因为队列任务可能使用序列化的旧对象）
        $this->order->refresh();
        
        // 重新加载关联数据，确保关联数据存在（refresh() 不会重新加载关联数据）
        $this->order->load(['otaPlatform', 'product']);
        
        Log::info('NotifyOtaOrderStatusJob 开始执行', [
            'order_id' => $this->order->id,
            'order_status' => $this->order->status->value,
            'ota_platform_id' => $this->order->ota_platform_id,
            'ota_platform_code' => $this->order->otaPlatform?->code?->value,
            'has_ota_platform' => $this->order->otaPlatform !== null,
        ]);
        
        // 使用工厂类创建对应的通知服务（策略模式）
        $notification = NotificationFactory::create($this->order);
        
        if (!$notification) {
            Log::warning('NotifyOtaOrderStatusJob: 不支持的OTA平台或平台配置不存在', [
                'order_id' => $this->order->id,
                'platform' => $this->order->otaPlatform?->code?->value,
            ]);
            return;
        }
        
        // 根据订单状态调用对应的通知方法
        try {
            if ($this->order->status->value === 'confirmed') {
                $notification->notifyOrderConfirmed($this->order);
            } elseif ($this->order->status->value === 'cancel_approved') {
                $notification->notifyOrderRefunded($this->order);
            } elseif ($this->order->status->value === 'verified') {
                $notification->notifyOrderConsumed($this->order);
            } else {
                Log::info('NotifyOtaOrderStatusJob: 订单状态无需通知', [
                    'order_id' => $this->order->id,
                    'order_status' => $this->order->status->value,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyOtaOrderStatusJob: 通知失败', [
                'order_id' => $this->order->id,
                'platform' => $this->order->otaPlatform?->code?->value,
                'order_status' => $this->order->status->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // 重新抛出异常，让队列重试
        }
    }
}
