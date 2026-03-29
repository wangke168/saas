<?php

namespace App\Jobs;

use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Services\DingTalkNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessResource*Job 产生待处理异常单后的钉钉告警（直连链路）
 */
class NotifyResourceChannelExceptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public function __construct(
        public int $orderId,
        public int $exceptionOrderId,
    ) {
        $this->onQueue('default');
    }

    public function handle(DingTalkNotificationService $dingTalkService): void
    {
        try {
            $order = Order::query()->find($this->orderId);
            $exception = ExceptionOrder::query()->find($this->exceptionOrderId);

            if (!$order || !$exception) {
                Log::warning('NotifyResourceChannelExceptionJob: 订单或异常单不存在', [
                    'order_id' => $this->orderId,
                    'exception_order_id' => $this->exceptionOrderId,
                ]);
                return;
            }

            if ((int) $exception->order_id !== (int) $order->id) {
                Log::warning('NotifyResourceChannelExceptionJob: 异常单与订单不匹配', [
                    'order_id' => $this->orderId,
                    'exception_order_id' => $this->exceptionOrderId,
                ]);
                return;
            }

            if ($exception->status->value !== 'pending') {
                Log::info('NotifyResourceChannelExceptionJob: 异常单非待处理，跳过钉钉', [
                    'exception_order_id' => $exception->id,
                    'status' => $exception->status->value,
                ]);
                return;
            }

            $success = $dingTalkService->sendResourceChannelExceptionNotification($order, $exception);
            if (!$success) {
                Log::warning('NotifyResourceChannelExceptionJob: 钉钉发送失败', [
                    'order_id' => $order->id,
                    'exception_order_id' => $exception->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NotifyResourceChannelExceptionJob: 执行异常', [
                'order_id' => $this->orderId,
                'exception_order_id' => $this->exceptionOrderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
