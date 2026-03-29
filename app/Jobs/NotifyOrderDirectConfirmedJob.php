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
 * 系统直连：资源接单且 OTA 确认通知成功后发送（与订单状态已确认一致）
 */
class NotifyOrderDirectConfirmedJob implements ShouldQueue
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

            Log::info('NotifyOrderDirectConfirmedJob 开始执行', [
                'order_id' => $this->order->id,
                'order_status' => $this->order->status->value,
            ]);

            if ($this->order->status->value !== 'confirmed') {
                Log::warning('NotifyOrderDirectConfirmedJob: 订单状态不是已确认，跳过通知', [
                    'order_id' => $this->order->id,
                    'order_status' => $this->order->status->value,
                ]);
                return;
            }

            $success = $dingTalkService->sendOrderDirectConfirmedNotification($this->order);
            if (!$success) {
                Log::warning('NotifyOrderDirectConfirmedJob: 钉钉通知发送失败', [
                    'order_id' => $this->order->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyOrderDirectConfirmedJob: 执行异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
