<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Jobs\ProcessResourceOrderJob;
use App\Models\Order;
use App\Models\User;
use App\Services\Resource\ResourceServiceFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderResourcePushRetryService
{
    private const COOLDOWN_SECONDS = 60;

    /**
     * 判断订单是否可重试向景区侧推送接单。
     */
    public function canRetry(Order $order): bool
    {
        if ($order->status !== OrderStatus::CONFIRMING) {
            return false;
        }

        return ResourceServiceFactory::isSystemConnected($order, 'order');
    }

    /**
     * 人工重试：重新向景区侧推送接单。
     */
    public function retry(Order $order, User $handler): void
    {
        if (! $this->canRetry($order)) {
            throw ValidationException::withMessages([
                'order' => ['仅系统直连且状态为确认中的订单可重试推送'],
            ]);
        }

        $lockKey = $this->lockKey($order);
        if (! Cache::add($lockKey, 1, self::COOLDOWN_SECONDS)) {
            throw ValidationException::withMessages([
                'order' => ['重试任务已提交，请 '.self::COOLDOWN_SECONDS.' 秒后再试'],
            ]);
        }

        ProcessResourceOrderJob::dispatch($order, 'confirm');

        Log::info('OrderResourcePushRetryService: 已派发景区推送重试', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'handler_id' => $handler->id,
        ]);
    }

    private function lockKey(Order $order): string
    {
        return 'order-resource-push-retry:'.$order->id;
    }
}
