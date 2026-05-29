<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\DingTalkNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 美团 /order/refunded、/order/close 直达取消通过时的钉钉通知（未经 cancel_requested 审批流）
 */
class NotifyMeituanOrderForceCancelledJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public function __construct(
        public Order $order,
        public string $source,
        public string $reason,
        public OrderStatus $previousStatus,
        public ?int $refundMessageType = null,
        public ?int $closeType = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(DingTalkNotificationService $dingTalkService): void
    {
        try {
            $this->order->refresh();

            Log::info('NotifyMeituanOrderForceCancelledJob 开始执行', [
                'order_id' => $this->order->id,
                'order_no' => $this->order->order_no,
                'order_status' => $this->order->status->value,
                'source' => $this->source,
                'previous_status' => $this->previousStatus->value,
            ]);

            if ($this->order->status !== OrderStatus::CANCEL_APPROVED) {
                Log::warning('NotifyMeituanOrderForceCancelledJob: 订单状态不是取消通过，跳过通知', [
                    'order_id' => $this->order->id,
                    'order_status' => $this->order->status->value,
                ]);

                return;
            }

            $success = $dingTalkService->sendMeituanForceCancelledNotification(
                $this->order,
                $this->source,
                $this->reason,
                $this->previousStatus,
                $this->refundMessageType,
                $this->closeType,
            );

            if ($success) {
                Log::info('NotifyMeituanOrderForceCancelledJob: 钉钉通知发送成功', [
                    'order_id' => $this->order->id,
                    'order_no' => $this->order->order_no,
                ]);
            } else {
                Log::warning('NotifyMeituanOrderForceCancelledJob: 钉钉通知发送失败', [
                    'order_id' => $this->order->id,
                    'order_no' => $this->order->order_no,
                    'note' => '请查看 DingTalkNotificationService 的日志以获取详细错误信息',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyMeituanOrderForceCancelledJob: 执行异常', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
