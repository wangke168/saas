<?php

namespace App\Jobs;

use App\Http\Controllers\Webhooks\ResourceController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 处理资源方库存推送（异步）
 * 
 * 接收资源方推送的库存数据，异步处理：
 * 1. 解析XML数据
 * 2. 通过Redis指纹比对，找出变化的库存
 * 3. 批量更新数据库（只更新变化的库存）
 * 4. 触发OTA推送（只推送变化的库存）
 */
class ProcessResourceInventoryPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数（包括首次尝试）
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 300; // 5分钟，因为可能需要处理大量数据

    /**
     * 计算重试延迟时间（指数退避）
     */
    public function backoff(): array
    {
        return [
            10,  // 第1次重试：10秒
            30,  // 第2次重试：30秒
        ];
    }

    /**
     * 创建任务实例
     * 
     * @param string $rawBody XML请求体
     * @param int $softwareProviderId 软件服务商ID，用于过滤酒店（避免不同服务商的酒店external_code冲突）
     */
    public function __construct(
        public string $rawBody,
        public int $softwareProviderId
    ) {
        // 使用 resource-push 队列，确保高优先级处理
        $this->onQueue('resource-push');
    }

    /**
     * 执行任务
     */
    public function handle(): void
    {
        Log::info('ProcessResourceInventoryPushJob 开始执行', [
            'body_length' => strlen($this->rawBody),
            'software_provider_id' => $this->softwareProviderId,
            'attempt' => $this->attempts(), // 当前尝试次数
        ]);

        try {
            // 创建Controller实例并调用同步处理逻辑
            // 注意：handleHengdianInventorySync是protected方法，需要通过反射调用
            // 未来可以考虑将处理逻辑提取到Service中，避免使用反射
            $controller = app(ResourceController::class);
            $reflection = new \ReflectionClass($controller);
            $method = $reflection->getMethod('handleHengdianInventorySync');
            $method->setAccessible(true);
            
            // 调用同步处理逻辑（返回JsonResponse，但Job中不需要返回）
            $method->invoke($controller, $this->rawBody, $this->softwareProviderId);
            
            Log::info('ProcessResourceInventoryPushJob 执行成功', [
                'body_length' => strlen($this->rawBody),
                'software_provider_id' => $this->softwareProviderId,
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessResourceInventoryPushJob 执行失败', [
                'body_length' => strlen($this->rawBody),
                'software_provider_id' => $this->softwareProviderId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // 如果达到最大尝试次数，记录错误但不抛出异常（避免无限重试）
            if ($this->attempts() >= $this->tries) {
                Log::error('ProcessResourceInventoryPushJob 达到最大尝试次数，停止重试', [
                    'body_length' => strlen($this->rawBody),
                    'software_provider_id' => $this->softwareProviderId,
                    'total_attempts' => $this->attempts(),
                ]);
                return;
            }
            
            throw $e; // 触发重试
        }
    }
}

