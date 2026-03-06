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

/**
 * 订单自动接单成功后的钉钉通知（库存充裕自动接单，标题为「新订单通知（自动接单）」）
 */
class NotifyOrderAutoConfirmedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public function __construct(
        public Order $order
    ) {
        $this->onQueue('default');
    }

    public function handle(DingTalkNotificationService $dingTalkService): void
    {
        try {
            $this->order->refresh();

            Log::info('NotifyOrderAutoConfirmedJob 开始执行', [
                'order_id' => $this->order->id,
                'order_no' => $this->order->order_no,
                'order_status' => $this->order->status->value,
            ]);

            if ($this->order->status->value !== 'confirmed') {
                Log::warning('NotifyOrderAutoConfirmedJob: 订单状态不是已确认，跳过通知', [
                    'order_id' => $this->order->id,
                    'order_status' => $this->order->status->value,
                ]);
                return;
            }

            $success = $dingTalkService->sendOrderAutoConfirmedNotification($this->order);

            if ($success) {
                Log::info('NotifyOrderAutoConfirmedJob: 钉钉通知发送成功', [
                    'order_id' => $this->order->id,
                    'order_no' => $this->order->order_no,
                ]);
            } else {
                Log::warning('NotifyOrderAutoConfirmedJob: 钉钉通知发送失败', [
                    'order_id' => $this->order->id,
                    'order_no' => $this->order->order_no,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyOrderAutoConfirmedJob: 执行异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
