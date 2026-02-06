<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Resource\ResourceServiceFactory;
use App\Services\Resource\FliggyDistributionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncFliggyOrderStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fliggy:sync-order-status {--days=7 : 查询最近几天的订单}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '同步飞猪订单状态（每5分钟执行一次）';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int)$this->option('days');
        
        $this->info("开始同步飞猪订单状态，查询最近{$days}天的订单");
        
        // 查询待确认/待支付的飞猪订单
        $orders = Order::whereHas('product.softwareProvider', function ($query) {
            $query->where('api_type', 'fliggy_distribution');
        })
        ->whereIn('status', [OrderStatus::CONFIRMING, OrderStatus::PAID_PENDING])
        ->whereNotNull('resource_order_no')
        ->where('created_at', '>=', now()->subDays($days))
        ->get();
        
        $this->info("找到 {$orders->count()} 个待同步的订单");
        
        $successCount = 0;
        $failCount = 0;
        
        foreach ($orders as $order) {
            try {
                $service = ResourceServiceFactory::getService($order);
                
                if (!$service instanceof FliggyDistributionService) {
                    $this->warn("订单 {$order->order_no} 不是飞猪订单，跳过");
                    continue;
                }
                
                $result = $service->queryOrderStatus($order);
                
                if ($result['success'] ?? false) {
                    $fliggyStatus = $result['data']['fliggy_status'] ?? null;
                    $ourStatus = $result['data']['status'] ?? null;
                    
                    $this->info("订单 {$order->order_no} 状态同步成功：飞猪状态={$fliggyStatus}, 系统状态={$ourStatus}");
                    $successCount++;
                } else {
                    $this->warn("订单 {$order->order_no} 状态同步失败：{$result['message'] ?? '未知错误'}");
                    $failCount++;
                }
                
            } catch (\Exception $e) {
                $this->error("订单 {$order->order_no} 状态同步异常：{$e->getMessage()}");
                Log::error('SyncFliggyOrderStatus: 订单状态同步异常', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'error' => $e->getMessage(),
                ]);
                $failCount++;
            }
        }
        
        $this->info("同步完成：成功 {$successCount} 个，失败 {$failCount} 个");
        
        return Command::SUCCESS;
    }
}
