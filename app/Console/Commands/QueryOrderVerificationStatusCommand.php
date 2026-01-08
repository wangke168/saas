<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\SoftwareProvider;
use App\Services\OrderVerificationService;
use App\Services\Resource\ResourceServiceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class QueryOrderVerificationStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order:query-verification-status 
                           {--order-id= : 查询指定订单ID}
                           {--batch-size=100 : 批量处理数量}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '查询订单核销状态（定时任务：每天凌晨2点执行）';

    /**
     * Execute the console command.
     */
    public function handle(OrderVerificationService $verificationService): int
    {
        $orderId = $this->option('order-id');
        $batchSize = (int)$this->option('batch-size');

        if ($orderId) {
            // 查询指定订单
            return $this->querySingleOrder($orderId, $verificationService);
        }

        // 批量查询待核销订单
        return $this->queryOrdersBatch($batchSize, $verificationService);
    }

    /**
     * 查询单个订单
     */
    protected function querySingleOrder(int $orderId, OrderVerificationService $verificationService): int
    {
        $this->info("查询订单 ID: {$orderId}");

        $order = Order::with(['otaPlatform', 'product', 'hotel.scenicSpot.softwareProvider'])
            ->find($orderId);

        if (!$order) {
            $this->error("订单不存在: {$orderId}");
            return Command::FAILURE;
        }

        $result = $this->queryOrderStatus($order, $verificationService);
        
        if ($result['success']) {
            $this->info("查询成功: " . ($result['message'] ?? ''));
            return Command::SUCCESS;
        } else {
            $this->error("查询失败: " . ($result['message'] ?? ''));
            return Command::FAILURE;
        }
    }

    /**
     * 批量查询订单
     */
    protected function queryOrdersBatch(int $batchSize, OrderVerificationService $verificationService): int
    {
        $this->info("开始批量查询订单核销状态，批量大小: {$batchSize}");

        // 查询需要检查核销状态的订单
        // 条件：状态为CONFIRMED，入住日期已到或已过
        $orders = Order::with(['otaPlatform', 'product', 'hotel.scenicSpot.softwareProvider'])
            ->where('status', OrderStatus::CONFIRMED)
            ->where('check_in_date', '<=', now()->format('Y-m-d'))
            ->orderBy('check_in_date', 'asc')
            ->limit($batchSize)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('没有需要查询的订单');
            return Command::SUCCESS;
        }

        $this->info("找到 {$orders->count()} 个订单需要查询");

        $successCount = 0;
        $failCount = 0;
        $skipCount = 0;

        foreach ($orders as $order) {
            // 检查订单关联的软件服务商是否支持查询
            $scenicSpot = $order->hotel->scenicSpot ?? null;
            if (!$scenicSpot) {
                $skipCount++;
                $this->warn("订单 {$order->id} 没有关联景区，跳过");
                continue;
            }

            // 获取软件服务商
            $softwareProvider = $scenicSpot->softwareProvider ?? null;
            if (!$softwareProvider) {
                $skipCount++;
                $this->warn("订单 {$order->id} 关联的景区没有软件服务商，跳过");
                continue;
            }

            // 检查软件服务商的核销同步配置
            $config = $softwareProvider->verification_config ?? [];
            $method = $config['method'] ?? 'query'; // 默认使用query方式

            // 如果配置为webhook模式，跳过查询（不主动查询，只接收推送）
            if ($method === 'webhook') {
                $skipCount++;
                $this->info("订单 {$order->id} 的软件服务商使用webhook模式，跳过主动查询");
                continue;
            }

            // 查询订单状态
            $result = $this->queryOrderStatus($order, $verificationService);
            
            if ($result['success']) {
                $successCount++;
                $this->info("订单 {$order->id} 查询成功");
            } else {
                $failCount++;
                $this->error("订单 {$order->id} 查询失败: " . ($result['message'] ?? ''));
            }
        }

        $this->info("批量查询完成：成功 {$successCount}，失败 {$failCount}，跳过 {$skipCount}");

        return Command::SUCCESS;
    }

    /**
     * 查询单个订单的状态
     */
    protected function queryOrderStatus(Order $order, OrderVerificationService $verificationService): array
    {
        try {
            // 获取资源服务
            $resourceService = ResourceServiceFactory::getService($order, 'order');
            
            if (!$resourceService) {
                return [
                    'success' => false,
                    'message' => '无法获取资源服务，订单可能不是系统直连',
                ];
            }

            // 检查资源服务是否实现了queryOrderStatus方法
            if (!method_exists($resourceService, 'queryOrderStatus')) {
                return [
                    'success' => false,
                    'message' => '资源服务不支持查询订单状态',
                ];
            }

            // 查询订单状态
            $result = $resourceService->queryOrderStatus($order);

            if (!($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? '查询订单状态失败',
                ];
            }

            $verificationData = $result['data'] ?? [];
            if (empty($verificationData)) {
                return [
                    'success' => false,
                    'message' => '查询结果数据为空',
                ];
            }

            // 处理核销状态（异步处理，避免阻塞）
            \App\Jobs\ProcessOrderVerificationJob::dispatch($order->id, $verificationData, 'query')
                ->onQueue('order-verification');

            Log::info('定时任务：订单核销状态查询成功，已放入队列处理', [
                'order_id' => $order->id,
                'status' => $verificationData['status'] ?? 'unknown',
            ]);

            return [
                'success' => true,
                'message' => '查询成功，已放入队列处理',
                'data' => $verificationData,
            ];
        } catch (\Exception $e) {
            Log::error('定时任务：订单核销状态查询异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '查询异常：' . $e->getMessage(),
            ];
        }
    }
}

