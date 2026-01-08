<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\DingTalkNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyOrderConfirmingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 30;

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
        // 使用默认队列
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(DingTalkNotificationService $dingTalkService): void
    {
        try {
            // 刷新订单对象，确保状态是最新的
            $this->order->refresh();

            Log::info('NotifyOrderConfirmingJob 开始执行', [
                'order_id' => $this->order->id,
                'order_no' => $this->order->order_no,
                'order_status' => $this->order->status->value,
            ]);

            // 检查订单状态是否为确认中
            if ($this->order->status->value !== 'confirming') {
                Log::warning('NotifyOrderConfirmingJob: 订单状态不是确认中，跳过通知', [
                    'order_id' => $this->order->id,
                    'order_status' => $this->order->status->value,
                ]);
                return;
            }

            // 发送钉钉通知
            $success = $dingTalkService->sendOrderConfirmingNotification($this->order);

            if ($success) {
                Log::info('NotifyOrderConfirmingJob: 钉钉通知发送成功', [
                    'order_id' => $this->order->id,
                ]);
            } else {
                Log::warning('NotifyOrderConfirmingJob: 钉钉通知发送失败', [
                    'order_id' => $this->order->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyOrderConfirmingJob: 执行异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // 重新抛出异常，让队列重试
        }
    }
}

