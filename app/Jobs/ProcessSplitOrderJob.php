<?php

namespace App\Jobs;

use App\Models\SystemPkgOrder;
use App\Services\OrderSplitterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 处理拆单任务
 */
class ProcessSplitOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 300; // 5分钟

    /**
     * @param int $orderId 订单ID
     */
    public function __construct(
        public int $orderId
    ) {}

    /**
     * 执行任务
     */
    public function handle(OrderSplitterService $splitterService): void
    {
        try {
            $order = SystemPkgOrder::find($this->orderId);
            if (!$order) {
                Log::warning('ProcessSplitOrderJob: 订单不存在', [
                    'order_id' => $this->orderId,
                ]);
                return;
            }

            Log::info('ProcessSplitOrderJob: 开始处理拆单', [
                'order_id' => $this->orderId,
                'order_no' => $order->order_no,
            ]);

            $splitterService->processSplitOrder($order);

            Log::info('ProcessSplitOrderJob: 拆单处理完成', [
                'order_id' => $this->orderId,
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessSplitOrderJob: 处理失败', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // 触发重试
        }
    }
}


