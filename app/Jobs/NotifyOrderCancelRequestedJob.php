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

class NotifyOrderCancelRequestedJob implements ShouldQueue
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
     * 取消相关数据
     */
    public array $cancelData;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order,
        array $cancelData = []
    ) {
        $this->cancelData = $cancelData;
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

            Log::info('NotifyOrderCancelRequestedJob 开始执行', [
                'order_id' => $this->order->id,
                'order_no' => $this->order->order_no,
                'order_status' => $this->order->status->value,
            ]);

            // 检查订单状态是否为取消申请中
            if ($this->order->status->value !== 'cancel_requested') {
                Log::warning('NotifyOrderCancelRequestedJob: 订单状态不是取消申请中，跳过通知', [
                    'order_id' => $this->order->id,
                    'order_status' => $this->order->status->value,
                ]);
                return;
            }

            // 发送钉钉通知
            $success = $dingTalkService->sendOrderCancelRequestedNotification($this->order, $this->cancelData);

            if ($success) {
                Log::info('NotifyOrderCancelRequestedJob: 钉钉通知发送成功', [
                    'order_id' => $this->order->id,
                ]);
            } else {
                Log::warning('NotifyOrderCancelRequestedJob: 钉钉通知发送失败', [
                    'order_id' => $this->order->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyOrderCancelRequestedJob: 执行异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // 重新抛出异常，让队列重试
        }
    }
}

