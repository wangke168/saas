<?php

namespace App\Jobs;

use App\Models\OrderExternalPushLog;
use App\Services\ExternalOrder\ExternalOrderPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushExternalOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public string $orderType,
        public int $orderId,
        public string $pushType,
        public int $routeOrderStatus,
    ) {
        $this->onQueue('external-order-push');
    }

    public function handle(ExternalOrderPushService $pushService): void
    {
        $pushService->push(
            $this->orderType,
            $this->orderId,
            $this->pushType,
            $this->routeOrderStatus,
            $this->attempts(),
        );
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('PushExternalOrderJob: 重试耗尽仍失败', [
            'order_type' => $this->orderType,
            'order_id' => $this->orderId,
            'push_type' => $this->pushType,
            'route_order_status' => $this->routeOrderStatus,
            'error' => $exception?->getMessage(),
        ]);
    }
}
