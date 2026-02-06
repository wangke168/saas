<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 处理订单核销状态Job
 * 异步处理订单核销状态，避免阻塞webhook响应
 */
class ProcessOrderVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 120;

    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $orderId,
        public array $verificationData,
        public string $source = 'query'
    ) {
        $this->onQueue('order-verification');
    }

    /**
     * Execute the job.
     */
    public function handle(OrderVerificationService $verificationService): void
    {
        try {
            // 重新加载订单，确保数据是最新的
            $order = Order::with(['otaPlatform', 'product'])->find($this->orderId);
            
            if (!$order) {
                Log::error('ProcessOrderVerificationJob: 订单不存在', [
                    'order_id' => $this->orderId,
                ]);
                return;
            }

            Log::info('ProcessOrderVerificationJob 开始执行', [
                'order_id' => $order->id,
                'source' => $this->source,
                'verification_data' => $this->verificationData,
            ]);

            // 调用订单核销处理服务
            $result = $verificationService->handleVerificationStatus(
                $order,
                $this->verificationData,
                $this->source
            );

            if ($result['success']) {
                Log::info('ProcessOrderVerificationJob 执行成功', [
                    'order_id' => $order->id,
                    'source' => $this->source,
                ]);
            } else {
                Log::warning('ProcessOrderVerificationJob 执行失败', [
                    'order_id' => $order->id,
                    'source' => $this->source,
                    'message' => $result['message'] ?? '未知错误',
                ]);
                // 不抛出异常，避免重试（除非是临时性错误）
                if (str_contains($result['message'] ?? '', '状态不允许')) {
                    // 状态不允许核销是业务错误，不需要重试
                    return;
                }
                // 其他错误可以重试
                throw new \Exception($result['message'] ?? '处理失败');
            }
        } catch (\Exception $e) {
            Log::error('ProcessOrderVerificationJob 执行异常', [
                'order_id' => $this->orderId,
                'source' => $this->source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // 重新抛出异常，让队列重试
        }
    }
}



